<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tender review initiation and lifecycle (§1.1), the evaluator roster drawn from a committee
 * (§1.2), the bidder list, and the gate that opens evaluation.
 *
 * The gate is the important part. §1.3 says a member cannot participate before declaring, §1.4 says
 * criteria freeze once evaluation begins, and §1.6 depends on weights summing correctly. Those are
 * three separate requirements with one natural enforcement point: `openEvaluation()` refuses unless
 * all of them hold, and freezes the criteria as it opens. Checking them at scoring time instead
 * would mean discovering the problem after evaluators had already done the work.
 */
class TenderReviewService
{
    /** §1.1 lifecycle. Cancellation is reachable from anything not yet awarded. */
    public const TRANSITIONS = [
        'DRAFT' => ['COMMITTEE_SETUP', 'CANCELLED'],
        'COMMITTEE_SETUP' => ['CRITERIA_SETUP', 'DRAFT', 'CANCELLED'],
        'CRITERIA_SETUP' => ['EVALUATION', 'COMMITTEE_SETUP', 'CANCELLED'],
        'EVALUATION' => ['MODERATION', 'CANCELLED'],
        'MODERATION' => ['RECOMMENDATION', 'EVALUATION', 'CANCELLED'],
        'RECOMMENDATION' => ['APPROVAL', 'MODERATION', 'CANCELLED'],
        'APPROVAL' => ['AWARDED', 'RECOMMENDATION', 'CANCELLED'],
        'AWARDED' => [],
        'CANCELLED' => [],
    ];

    public const METHODOLOGIES = ['WEIGHTED', 'TWO_ENVELOPE', 'PRICE_ONLY', 'PRICE_PREFERENCE'];

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLedger $audit,
        private readonly ScoringEngine $scoring,
        private readonly SettingsService $settings,
        private readonly RecordService $records,
        private readonly RulesEngine $rules,
    ) {}

    /**
     * §1.1 — initiate a review. Creates the unique Tender Review ID, and opens or joins the 360°
     * record so the review sits on the same spine as the request, plan line and eventual contract.
     *
     * @param  array<string,mixed>  $data
     */
    public function initiate(int $companyId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $data) {
            $now = Carbon::now();
            $reference = $this->references->next($companyId, 'review');

            $recordId = $data['record_id'] ?? null;

            // A review raised from a plan line inherits that line's record rather than starting a
            // second spine for the same procurement.
            if (! $recordId && ! empty($data['plan_item_id'])) {
                $recordId = DB::table('procgov_plan_items')
                    ->where('company_id', $companyId)
                    ->where('id', $data['plan_item_id'])
                    ->value('record_id');
            }

            if (! $recordId) {
                $record = $this->records->open($companyId, [
                    'title' => $data['title'],
                    'category' => $data['category'] ?? null,
                    'method' => 'OPEN_TENDER',
                    'company_unit_id' => $data['company_unit_id'] ?? null,
                    'project_id' => $data['project_id'] ?? null,
                    'officer_id' => $data['officer_id'] ?? auth()->id(),
                    'estimated_value' => $data['estimated_value'] ?? null,
                    'currency' => $data['currency'] ?? 'ZAR',
                    'stage' => 'SOURCING',
                ]);
                $recordId = (int) $record->id;
            }

            $reviewId = DB::table('procgov_tender_reviews')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'reference' => $reference,
                'tender_number' => $data['tender_number'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'company_unit_id' => $data['company_unit_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'officer_id' => $data['officer_id'] ?? auth()->id(),
                'estimated_value' => $data['estimated_value'] ?? null,
                'currency' => $data['currency'] ?? $this->settings->get($companyId)->currency,

                // §1.6. Left null when not chosen, so the tender inherits the tenant's jurisdiction
                // and its default framework rather than being pinned to today's setting forever.
                'jurisdiction' => $data['jurisdiction'] ?? null,
                'preference_framework_code' => $data['preference_framework_code'] ?? null,

                'budget_id' => $data['budget_id'] ?? null,
                'tender_closing_at' => $data['tender_closing_at'] ?? null,
                'evaluation_start_date' => $data['evaluation_start_date'] ?? null,
                'evaluation_deadline' => $data['evaluation_deadline'] ?? null,
                'methodology' => $data['methodology'] ?? 'WEIGHTED',
                'price_weight' => $data['price_weight'] ?? null,
                'preference_weight' => $data['preference_weight'] ?? null,
                'committee_id' => $data['committee_id'] ?? null,
                'required_approval_level' => $data['required_approval_level'] ?? null,
                'record_id' => $recordId,
                'plan_item_id' => $data['plan_item_id'] ?? null,
                'purchase_request_id' => $data['purchase_request_id'] ?? null,
                'requisition_id' => $data['requisition_id'] ?? null,
                'rfx_event_id' => $data['rfx_event_id'] ?? null,
                'status' => 'DRAFT',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->records->link($companyId, (int) $recordId, [
                'link_role' => 'tender',
                'entity_type' => 'procgov_tender_reviews',
                'entity_id' => $reviewId,
                'entity_reference' => $reference,
                'label' => $data['title'],
                'amount' => $data['estimated_value'] ?? null,
                'state' => 'DRAFT',
            ]);

            if (! empty($data['committee_id'])) {
                $this->syncEvaluatorsFromCommittee($companyId, $reviewId, (int) $data['committee_id']);
            }

            $this->audit->record($companyId, [
                'action' => 'created',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_tender_reviews',
                'entity_id' => $reviewId,
                'entity_reference' => $reference,
                'record_id' => (int) $recordId,
                'summary' => "Initiated tender review {$reference} — {$data['title']}",
            ]);

            return DB::table('procgov_tender_reviews')->find($reviewId);
        });
    }

    /**
     * Draw the review's evaluator roster from a committee. Observers are included but non-voting,
     * and only ACTIVE members are drawn — a member whose appointment has ended does not evaluate.
     */
    public function syncEvaluatorsFromCommittee(int $companyId, int $reviewId, int $committeeId): int
    {
        $members = DB::table('procgov_committee_members')
            ->where('company_id', $companyId)
            ->where('committee_id', $committeeId)
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->get();

        $now = Carbon::now();
        $added = 0;

        foreach ($members as $member) {
            $exists = DB::table('procgov_review_evaluators')
                ->where('review_id', $reviewId)
                ->when($member->user_id, fn ($q) => $q->where('user_id', $member->user_id))
                ->when(! $member->user_id, fn ($q) => $q->where('committee_member_id', $member->id))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('procgov_review_evaluators')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'review_id' => $reviewId,
                'committee_member_id' => $member->id,
                'user_id' => $member->user_id,
                'is_external' => $member->is_external,
                'external_name' => $member->external_name,
                'external_email' => $member->external_email,
                'member_role' => $member->member_role,
                'is_voting' => $member->is_voting && $member->member_role !== 'OBSERVER',
                'coi_status' => 'NOT_COMPLETED',
                'invited_at' => $now,
                'status' => 'ASSIGNED',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $added++;
        }

        DB::table('procgov_tender_reviews')->where('id', $reviewId)->update([
            'committee_id' => $committeeId,
            'updated_at' => $now,
        ]);

        return $added;
    }

    /** @param  array<string,mixed>  $data */
    public function addBidder(int $companyId, int $reviewId, array $data): object
    {
        $review = DB::table('procgov_tender_reviews')->find($reviewId);

        if ($review && $review->criteria_frozen_at) {
            throw new DomainRuleException(
                'Evaluation has already begun, so the bidder list cannot be changed. Bidders added '
                .'after scoring started would not have been assessed by every evaluator.'
            );
        }

        $now = Carbon::now();

        $id = DB::table('procgov_bidders')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'review_id' => $reviewId,
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_name' => $data['supplier_name'],
            'registration_number' => $data['registration_number'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'bid_reference' => $data['bid_reference'] ?? null,
            'bid_amount' => $data['bid_amount'] ?? null,
            'currency' => $data['currency'] ?? 'ZAR',
            'submitted_at' => $data['submitted_at'] ?? null,
            // A bid received after the advertised closing time is a compliance fact, computed once
            // and stored, because the closing time itself can legitimately be corrected later.
            'is_late' => $this->isLate($review, $data['submitted_at'] ?? null),
            'bbbee_level' => $data['bbbee_level'] ?? null,
            'preference_points' => $data['preference_points'] ?? null,
            'status' => 'SUBMITTED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->record($companyId, [
            'action' => 'bidder_added',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_bidders',
            'entity_id' => $id,
            'entity_reference' => $review?->reference,
            'record_id' => $review?->record_id,
            'summary' => "Recorded bid from {$data['supplier_name']} on {$review?->reference}",
            'after' => ['bid_amount' => $data['bid_amount'] ?? null],
        ]);

        return DB::table('procgov_bidders')->find($id);
    }

    /**
     * §1.8 requires disqualifications and their reasons in the report, so a reason is mandatory —
     * a disqualification without one is unreviewable and, in practice, indefensible.
     */
    public function disqualify(int $companyId, int $bidderId, string $stage, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainRuleException('A disqualification must record its reason.');
        }

        $bidder = DB::table('procgov_bidders')->find($bidderId);

        if (! $bidder) {
            return;
        }

        DB::table('procgov_bidders')->where('id', $bidderId)->update([
            'is_disqualified' => true,
            'disqualification_stage' => $stage,
            'disqualification_reason' => $reason,
            'disqualified_by' => auth()->id(),
            'disqualified_at' => Carbon::now(),
            'status' => 'DISQUALIFIED',
            'updated_at' => Carbon::now(),
        ]);

        $this->scoring->computeReview($companyId, (int) $bidder->review_id);

        $this->audit->record($companyId, [
            'action' => 'bidder_disqualified',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_bidders',
            'entity_id' => $bidderId,
            'decision' => 'DISQUALIFIED',
            'comment' => $reason,
            'summary' => "Disqualified {$bidder->supplier_name} at {$stage} stage",
        ]);
    }

    /** Reinstate a bidder — recorded as its own act, with its own reason. */
    public function reinstate(int $companyId, int $bidderId, string $reason): void
    {
        $bidder = DB::table('procgov_bidders')->find($bidderId);

        if (! $bidder || ! $bidder->is_disqualified) {
            return;
        }

        DB::table('procgov_bidders')->where('id', $bidderId)->update([
            'is_disqualified' => false,
            'disqualification_stage' => null,
            'disqualification_reason' => null,
            'disqualified_by' => null,
            'disqualified_at' => null,
            'status' => 'EVALUATING',
            'updated_at' => Carbon::now(),
        ]);

        $this->scoring->computeReview($companyId, (int) $bidder->review_id);

        $this->audit->record($companyId, [
            'action' => 'bidder_reinstated',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_bidders',
            'entity_id' => $bidderId,
            'before' => ['is_disqualified' => true, 'reason' => $bidder->disqualification_reason],
            'after' => ['is_disqualified' => false],
            'comment' => $reason,
        ]);
    }

    /**
     * §1.4 + §1.3: the gate into evaluation. Refuses with every reason at once rather than one at a
     * time, so the officer fixes the whole set in one pass.
     *
     * @return list<string> blocking problems; empty when evaluation was opened
     */
    public function openEvaluation(int $companyId, int $reviewId): array
    {
        $review = DB::table('procgov_tender_reviews')->find($reviewId);

        if (! $review) {
            return ['Tender review not found.'];
        }

        if ($review->criteria_frozen_at) {
            return [];   // already open
        }

        $problems = $this->scoring->validateWeights($companyId, $reviewId);

        $bidders = DB::table('procgov_bidders')
            ->where('review_id', $reviewId)
            ->whereNull('deleted_at')
            ->count();

        if ($bidders === 0) {
            $problems[] = 'No bids have been recorded against this tender.';
        }

        $evaluators = DB::table('procgov_review_evaluators')
            ->where('review_id', $reviewId)
            ->where('is_voting', true)
            ->whereNotIn('status', ['REMOVED', 'RECUSED'])
            ->get();

        if ($evaluators->isEmpty()) {
            $problems[] = 'No voting evaluators are assigned to this review.';
        }

        if ($this->settings->get($companyId)->require_coi_declaration) {
            $outstanding = $evaluators->filter(fn ($e) => $e->coi_status === 'NOT_COMPLETED')->count();
            $conflicted = $evaluators->filter(fn ($e) => $e->coi_status === 'CONFLICT_IDENTIFIED')->count();

            if ($outstanding > 0) {
                $problems[] = "{$outstanding} evaluator(s) have not completed a conflict-of-interest declaration.";
            }

            if ($conflicted > 0) {
                $problems[] = "{$conflicted} declared conflict(s) are unresolved. Evaluation cannot begin until they are.";
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        DB::transaction(function () use ($companyId, $reviewId, $review) {
            $now = Carbon::now();

            DB::table('procgov_tender_reviews')->where('id', $reviewId)->update([
                'status' => 'EVALUATION',
                'criteria_frozen_at' => $now,
                'evaluation_opened_at' => $now,
                'updated_by' => auth()->id(),
                'updated_at' => $now,
            ]);

            DB::table('procgov_bidders')
                ->where('review_id', $reviewId)
                ->where('status', 'SUBMITTED')
                ->update(['status' => 'EVALUATING', 'updated_at' => $now]);

            if ($review->record_id) {
                $this->records->transition($companyId, (int) $review->record_id, 'EVALUATION', 'Tender evaluation opened');
            }

            $this->audit->record($companyId, [
                'action' => 'evaluation_opened',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_tender_reviews',
                'entity_id' => $reviewId,
                'entity_reference' => $review->reference,
                'record_id' => $review->record_id,
                'summary' => "Opened evaluation on {$review->reference}; criteria frozen",
            ]);
        });

        return [];
    }

    /** @throws \RuntimeException on a transition the lifecycle does not allow */
    public function transition(int $companyId, int $reviewId, string $toStatus, ?string $comment = null): object
    {
        return DB::transaction(function () use ($companyId, $reviewId, $toStatus, $comment) {
            $review = DB::table('procgov_tender_reviews')->lockForUpdate()->find($reviewId);

            if (! $review) {
                throw new DomainRuleException('Tender review not found.');
            }

            $allowed = self::TRANSITIONS[$review->status] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw new DomainRuleException(
                    "A review in {$review->status} cannot move to {$toStatus}."
                    .($allowed === [] ? '' : ' Allowed: '.implode(', ', $allowed).'.')
                );
            }

            $now = Carbon::now();
            $update = ['status' => $toStatus, 'updated_by' => auth()->id(), 'updated_at' => $now];

            if ($toStatus === 'MODERATION') {
                $update['evaluation_closed_at'] = $now;
            }

            if ($toStatus === 'AWARDED') {
                $update['awarded_at'] = $now;
            }

            if ($toStatus === 'CANCELLED') {
                $update['cancellation_reason'] = $comment;
            }

            DB::table('procgov_tender_reviews')->where('id', $reviewId)->update($update);

            if ($review->record_id) {
                $stage = match ($toStatus) {
                    'MODERATION', 'RECOMMENDATION' => 'RECOMMENDATION',
                    'APPROVAL' => 'APPROVAL',
                    'AWARDED' => 'AWARD',
                    default => null,
                };

                if ($stage) {
                    $this->records->transition($companyId, (int) $review->record_id, $stage, $comment);
                }
            }

            $this->audit->record($companyId, [
                'action' => 'status_changed',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_tender_reviews',
                'entity_id' => $reviewId,
                'entity_reference' => $review->reference,
                'record_id' => $review->record_id,
                'before' => ['status' => $review->status],
                'after' => ['status' => $toStatus],
                'comment' => $comment,
            ]);

            return DB::table('procgov_tender_reviews')->find($reviewId);
        });
    }

    /** Where each evaluator has got to — the officer's view of progress (§1.7 input). */
    public function progress(int $companyId, int $reviewId): Collection
    {
        $bidderCount = DB::table('procgov_bidders')
            ->where('review_id', $reviewId)
            ->whereNull('deleted_at')
            ->where('is_disqualified', false)
            ->count();

        return DB::table('procgov_review_evaluators as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('e.company_id', $companyId)
            ->where('e.review_id', $reviewId)
            ->get([
                'e.id', 'e.uuid', 'e.user_id', 'e.is_external', 'e.external_name', 'e.member_role',
                'e.is_voting', 'e.coi_status', 'e.confidentiality_accepted_at', 'e.status',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                'u.email as user_email',
            ])
            ->map(function ($e) use ($bidderCount) {
                $e->display_name = $e->is_external
                    ? $e->external_name
                    : (trim((string) $e->user_name) ?: $e->user_email);
                unset($e->user_name);

                $e->submitted = DB::table('procgov_evaluations')
                    ->where('evaluator_id', $e->id)
                    ->where('status', 'SUBMITTED')
                    ->count();
                $e->expected = $bidderCount;
                $e->complete = $bidderCount > 0 && $e->submitted >= $bidderCount;

                return $e;
            });
    }

    /** The rules-engine view of a review, for the pre-open compliance check. */
    public function asRuleSubject(int $companyId, object $review): RuleSubject
    {
        $bidderCount = DB::table('procgov_bidders')
            ->where('review_id', $review->id)
            ->whereNull('deleted_at')
            ->count();

        return new RuleSubject(
            companyId: $companyId,
            type: 'procgov_tender_reviews',
            id: (int) $review->id,
            reference: $review->reference,
            fields: (array) $review,
            amount: $review->estimated_value !== null ? (float) $review->estimated_value : null,
            category: $review->category,
            companyUnitId: $review->company_unit_id ? (int) $review->company_unit_id : null,
            method: 'OPEN_TENDER',
            subtype: $review->methodology,
            status: $review->status,
            recordId: $review->record_id ? (int) $review->record_id : null,
            budgetId: $review->budget_id ? (int) $review->budget_id : null,
            meta: ['quote_count' => $bidderCount],
        );
    }

    public function complianceCheck(int $companyId, object $review, string $trigger = 'manual'): object
    {
        return $this->rules->check($this->asRuleSubject($companyId, $review), $trigger);
    }

    private function isLate(?object $review, ?string $submittedAt): bool
    {
        if (! $review || ! $review->tender_closing_at || ! $submittedAt) {
            return false;
        }

        return Carbon::parse($submittedAt)->gt(Carbon::parse($review->tender_closing_at));
    }
}
