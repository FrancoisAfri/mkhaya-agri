<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Evaluation moderation (§1.7).
 *
 * Moderation is the chairperson's chance to look across evaluators before anything is
 * recommended — and the first point at which anyone is *supposed* to see the comparative picture.
 * Opening it therefore also opens the comparative view, which individual evaluators never had.
 *
 * The system surfaces the candidates for attention rather than waiting to be asked: material
 * scoring differences (against the tenant's own variance threshold), evaluator concern flags,
 * bidders that failed a threshold, and criteria nobody scored. A moderator who has to go looking
 * for disagreements finds the loud ones and misses the quiet ones.
 *
 * The moderator may not alter anyone's score. They can ask for clarification, send a criterion back
 * to an evaluator for re-evaluation — which reopens *that evaluator's* evaluation through the same
 * audited path any other correction uses — record a comment, or accept. Anything else would let one
 * person's judgement replace the committee's while still being reported as the committee's.
 */
class ModerationService
{
    public const ACTIONS = ['CLARIFY', 'RE_EVALUATE', 'ACCEPT', 'ADJUST', 'NOTED'];

    public function __construct(
        private readonly ScoringEngine $scoring,
        private readonly EvaluationService $evaluations,
        private readonly SettingsService $settings,
        private readonly AuditLedger $audit,
    ) {}

    /**
     * Open moderation on a review, seeding it with everything worth a second look.
     */
    public function open(int $companyId, int $reviewId): object
    {
        $existing = DB::table('procgov_moderations')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->whereIn('status', ['OPEN', 'AWAITING_RESPONSE'])
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($companyId, $reviewId) {
            $now = Carbon::now();
            $review = DB::table('procgov_tender_reviews')->find($reviewId);

            $moderationId = DB::table('procgov_moderations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'review_id' => $reviewId,
                'moderator_id' => auth()->id(),
                'status' => 'OPEN',
                'opened_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $seeded = $this->seedItems($companyId, $moderationId, $reviewId);

            $this->audit->record($companyId, [
                'action' => 'moderation_opened',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_moderations',
                'entity_id' => $moderationId,
                'entity_reference' => $review?->reference,
                'record_id' => $review?->record_id,
                'summary' => "Opened moderation on {$review?->reference}; {$seeded} item(s) flagged for review",
            ]);

            return DB::table('procgov_moderations')->find($moderationId);
        });
    }

    /**
     * Record a moderation decision on an item.
     *
     * RE_EVALUATE reopens the target evaluator's evaluation, which archives their current scores
     * first — the moderator never edits a score directly.
     */
    public function act(int $companyId, int $itemId, string $action, ?string $comment = null): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new DomainRuleException('Unknown moderation action.');
        }

        if (in_array($action, ['RE_EVALUATE', 'ADJUST'], true) && trim((string) $comment) === '') {
            throw new DomainRuleException('Asking for a re-evaluation requires a comment explaining why.');
        }

        DB::transaction(function () use ($companyId, $itemId, $action, $comment) {
            $item = DB::table('procgov_moderation_items')->lockForUpdate()->find($itemId);

            if (! $item) {
                throw new DomainRuleException('Moderation item not found.');
            }

            $now = Carbon::now();

            DB::table('procgov_moderation_items')->where('id', $itemId)->update([
                'action' => $action,
                'moderator_comment' => $comment,
                'status' => in_array($action, ['ACCEPT', 'NOTED'], true) ? 'RESOLVED' : 'AWAITING_RESPONSE',
                'resolved_at' => in_array($action, ['ACCEPT', 'NOTED'], true) ? $now : null,
                'updated_at' => $now,
            ]);

            if ($action === 'RE_EVALUATE' && $item->evaluation_id) {
                $this->evaluations->reopen(
                    $companyId,
                    (int) $item->evaluation_id,
                    'Moderation: '.$comment,
                );
            }

            $moderation = DB::table('procgov_moderations')->find($item->moderation_id);
            $review = $moderation ? DB::table('procgov_tender_reviews')->find($moderation->review_id) : null;

            $this->audit->record($companyId, [
                'action' => 'moderation_action',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_moderation_items',
                'entity_id' => $itemId,
                'entity_reference' => $review?->reference,
                'record_id' => $review?->record_id,
                'decision' => $action,
                'comment' => $comment,
                'summary' => "Moderation action {$action} on {$review?->reference}",
            ]);
        });
    }

    /** An evaluator's reply to a clarification request. */
    public function respond(int $companyId, int $itemId, string $response): void
    {
        $now = Carbon::now();

        DB::table('procgov_moderation_items')->where('id', $itemId)->update([
            'evaluator_response' => $response,
            'responded_at' => $now,
            'status' => 'RESOLVED',
            'resolved_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->record($companyId, [
            'action' => 'moderation_response',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_moderation_items',
            'entity_id' => $itemId,
            'comment' => $response,
        ]);
    }

    /**
     * Approve the final scores — the gate into §1.8. Refuses while anything is unresolved, because
     * a recommendation report generated over an open re-evaluation request would be superseded the
     * moment that evaluator resubmits.
     *
     * @return list<string> problems preventing approval; empty when approved
     */
    public function approveScores(int $companyId, int $moderationId): array
    {
        $moderation = DB::table('procgov_moderations')->find($moderationId);

        if (! $moderation) {
            return ['Moderation not found.'];
        }

        $open = DB::table('procgov_moderation_items')
            ->where('moderation_id', $moderationId)
            ->whereNotIn('status', ['RESOLVED'])
            ->count();

        if ($open > 0) {
            return ["{$open} moderation item(s) are still open. Resolve them before approving the final scores."];
        }

        $unsubmitted = DB::table('procgov_review_evaluators')
            ->where('review_id', $moderation->review_id)
            ->where('is_voting', true)
            ->whereNotIn('status', ['SUBMITTED', 'RECUSED', 'REMOVED'])
            ->count();

        if ($unsubmitted > 0) {
            return ["{$unsubmitted} voting evaluator(s) have not submitted. The committee score is not complete."];
        }

        $this->scoring->computeReview($companyId, (int) $moderation->review_id);

        $now = Carbon::now();

        DB::table('procgov_moderations')->where('id', $moderationId)->update([
            'status' => 'RESOLVED',
            'closed_at' => $now,
            'scores_approved_at' => $now,
            'scores_approved_by' => auth()->id(),
            'updated_at' => $now,
        ]);

        $review = DB::table('procgov_tender_reviews')->find($moderation->review_id);

        $this->audit->record($companyId, [
            'action' => 'scores_approved',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_moderations',
            'entity_id' => $moderationId,
            'entity_reference' => $review?->reference,
            'record_id' => $review?->record_id,
            'decision' => 'APPROVED',
            'summary' => "Approved the final committee scores on {$review?->reference}",
        ]);

        return [];
    }

    /** Raise a moderation item by hand, for something the automatic sweep would not catch. */
    public function raise(int $companyId, int $moderationId, array $data): object
    {
        $now = Carbon::now();

        $id = DB::table('procgov_moderation_items')->insertGetId([
            'company_id' => $companyId,
            'moderation_id' => $moderationId,
            'bidder_id' => $data['bidder_id'] ?? null,
            'criterion_id' => $data['criterion_id'] ?? null,
            'evaluation_id' => $data['evaluation_id'] ?? null,
            'target_evaluator_id' => $data['target_evaluator_id'] ?? null,
            'item_type' => $data['item_type'] ?? 'MANUAL',
            'moderator_comment' => $data['comment'] ?? null,
            'status' => 'OPEN',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('procgov_moderation_items')->find($id);
    }

    /** The comparative view — every evaluator's score per criterion, per bidder. */
    public function comparison(int $companyId, int $reviewId): array
    {
        $rows = DB::table('procgov_evaluation_scores as s')
            ->join('procgov_evaluations as e', 'e.id', '=', 's.evaluation_id')
            ->join('procgov_criteria as c', 'c.id', '=', 's.criterion_id')
            ->join('procgov_bidders as b', 'b.id', '=', 'e.bidder_id')
            ->join('procgov_review_evaluators as ev', 'ev.id', '=', 'e.evaluator_id')
            ->leftJoin('users as u', 'u.id', '=', 'ev.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('e.company_id', $companyId)
            ->where('e.review_id', $reviewId)
            ->where('e.status', 'SUBMITTED')
            ->orderBy('b.supplier_name')
            ->orderBy('c.sort_order')
            ->get([
                'b.id as bidder_id', 'b.supplier_name',
                'c.id as criterion_id', 'c.name as criterion_name', 'c.max_score as criterion_max',
                's.score', 's.comment', 's.flagged', 's.passed',
                'ev.id as evaluator_id', 'ev.is_external', 'ev.external_name',
                'e.id as evaluation_id',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                'u.email as user_email',
            ])
            ->map(function ($r) {
                $r->evaluator_name = $r->is_external
                    ? $r->external_name
                    : (trim((string) $r->user_name) ?: $r->user_email);
                unset($r->user_name, $r->user_email);

                return $r;
            });

        return [
            'rows' => $rows,
            'bidders' => $rows->pluck('supplier_name', 'bidder_id')->unique(),
            'criteria' => $rows->unique('criterion_id')->map(fn ($r) => [
                'id' => $r->criterion_id,
                'name' => $r->criterion_name,
                'max' => $r->criterion_max,
            ])->values(),
            'variances' => $this->scoring->variances(
                $companyId,
                $reviewId,
                (float) $this->settings->get($companyId)->moderation_variance_threshold,
            ),
        ];
    }

    public function items(int $companyId, int $moderationId): Collection
    {
        return DB::table('procgov_moderation_items as i')
            ->leftJoin('procgov_bidders as b', 'b.id', '=', 'i.bidder_id')
            ->leftJoin('procgov_criteria as c', 'c.id', '=', 'i.criterion_id')
            ->where('i.company_id', $companyId)
            ->where('i.moderation_id', $moderationId)
            ->orderByRaw("case i.status when 'OPEN' then 0 when 'AWAITING_RESPONSE' then 1 else 2 end")
            ->orderByDesc('i.variance')
            ->get([
                'i.id', 'i.item_type', 'i.variance', 'i.lowest_score', 'i.highest_score',
                'i.action', 'i.moderator_comment', 'i.evaluator_response', 'i.responded_at',
                'i.status', 'i.resolved_at', 'i.target_evaluator_id', 'i.evaluation_id',
                'b.supplier_name', 'c.name as criterion_name',
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** Everything worth the moderator's attention, raised automatically. */
    private function seedItems(int $companyId, int $moderationId, int $reviewId): int
    {
        $now = Carbon::now();
        $threshold = (float) $this->settings->get($companyId)->moderation_variance_threshold;
        $seeded = 0;

        foreach ($this->scoring->variances($companyId, $reviewId, $threshold) as $variance) {
            DB::table('procgov_moderation_items')->insert([
                'company_id' => $companyId,
                'moderation_id' => $moderationId,
                'bidder_id' => $variance['bidder_id'],
                'criterion_id' => $variance['criterion_id'],
                'item_type' => 'VARIANCE',
                'variance' => $variance['variance'],
                'lowest_score' => $variance['lowest_score'],
                'highest_score' => $variance['highest_score'],
                'status' => 'OPEN',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $seeded++;
        }

        // Evaluator concerns (§1.5 "flag concerns") — raised by the person closest to the detail.
        $concerns = DB::table('procgov_evaluations as e')
            ->join('procgov_bidders as b', 'b.id', '=', 'e.bidder_id')
            ->where('e.review_id', $reviewId)
            ->where('e.status', 'SUBMITTED')
            ->where('e.has_concerns', true)
            ->get(['e.id', 'e.bidder_id', 'e.evaluator_id', 'e.concern_note']);

        foreach ($concerns as $concern) {
            DB::table('procgov_moderation_items')->insert([
                'company_id' => $companyId,
                'moderation_id' => $moderationId,
                'bidder_id' => $concern->bidder_id,
                'evaluation_id' => $concern->id,
                'target_evaluator_id' => $concern->evaluator_id,
                'item_type' => 'CONCERN',
                'moderator_comment' => null,
                'evaluator_response' => $concern->concern_note,
                'status' => 'OPEN',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $seeded++;
        }

        // Bidders who failed a minimum threshold — a material outcome that should be seen and
        // confirmed rather than applied silently.
        $failed = DB::table('procgov_bidders')
            ->where('review_id', $reviewId)
            ->where('threshold_passed', false)
            ->where('is_disqualified', false)
            ->get(['id']);

        foreach ($failed as $bidder) {
            DB::table('procgov_moderation_items')->insert([
                'company_id' => $companyId,
                'moderation_id' => $moderationId,
                'bidder_id' => $bidder->id,
                'item_type' => 'THRESHOLD',
                'status' => 'OPEN',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $seeded++;
        }

        return $seeded;
    }
}
