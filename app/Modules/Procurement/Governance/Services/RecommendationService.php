<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Support\Integration\ChainLinker;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The recommendation report (§1.8).
 *
 * §1.8 lists thirteen things the report must contain — tender information, participating suppliers,
 * criteria, committee members, individual scores, weighted scores, final ranking, disqualifications
 * and their reasons, committee comments, the recommendation, approval history, supporting documents
 * and audit information. Every one of them can legitimately change after the report is produced: a
 * committee member's appointment ends, a criterion is amended for the next tender, a supplier is
 * renamed.
 *
 * So the report is a **frozen snapshot**, assembled once and stored as JSON with a content hash.
 * The PDF is rendered from that JSON, never from a live query. A recommendation report that renders
 * differently a year later is not a report — it is a view, and the thing an approver signed no
 * longer exists.
 *
 * A superseded report is kept rather than replaced. Version 1 recommending supplier A, and version
 * 2 recommending supplier B after a re-evaluation, is the most important sequence in the whole
 * module.
 */
class RecommendationService
{
    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLedger $audit,
        private readonly ScoringEngine $scoring,
        private readonly ApprovalRouter $approvals,
        private readonly RecordService $records,
    ) {}

    /**
     * Generate the report. Refuses while moderation is incomplete, because a recommendation over
     * unapproved scores is a draft masquerading as a decision.
     *
     * @throws \RuntimeException
     */
    public function generate(int $companyId, int $reviewId, ?int $recommendedBidderId = null, array $narrative = []): object
    {
        $review = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->where('id', $reviewId)
            ->first();

        if (! $review) {
            throw new DomainRuleException('Tender review not found.');
        }

        $moderation = DB::table('procgov_moderations')
            ->where('review_id', $reviewId)
            ->orderByDesc('id')
            ->first();

        if (! $moderation || ! $moderation->scores_approved_at) {
            throw new DomainRuleException(
                'The committee scores have not been approved in moderation, so a recommendation '
                .'cannot be generated yet.'
            );
        }

        // Recompute rather than trust what is stored: the snapshot must reflect the arithmetic as
        // it stands at the moment of generation, not whatever was last written.
        $this->scoring->computeReview($companyId, $reviewId);

        return DB::transaction(function () use ($companyId, $reviewId, $review, $recommendedBidderId, $narrative) {
            $now = Carbon::now();

            $bidders = DB::table('procgov_bidders')
                ->where('review_id', $reviewId)
                ->whereNull('deleted_at')
                ->orderByRaw('overall_rank nulls last')
                ->orderBy('supplier_name')
                ->get();

            // The recommended bidder defaults to rank 1, but is overridable: the committee may
            // recommend otherwise, and when they do, the report carries their reasons.
            $recommended = $recommendedBidderId
                ? $bidders->firstWhere('id', $recommendedBidderId)
                : $bidders->firstWhere('overall_rank', 1);

            if (! $recommended) {
                throw new DomainRuleException(
                    'No bidder is eligible for recommendation — every bid was disqualified or failed '
                    .'a minimum threshold. Record the outcome as a cancellation instead.'
                );
            }

            // Supersede any earlier version rather than overwrite it.
            $version = (int) DB::table('procgov_recommendations')
                ->where('review_id', $reviewId)
                ->max('version') + 1;

            if ($version > 1) {
                DB::table('procgov_recommendations')
                    ->where('review_id', $reviewId)
                    ->whereIn('status', ['DRAFT', 'SUBMITTED'])
                    ->update(['status' => 'SUPERSEDED', 'updated_at' => $now]);
            }

            $reference = DB::table('procgov_recommendations')
                ->where('review_id', $reviewId)
                ->value('reference') ?? $this->references->next($companyId, 'review').'-REC';

            $snapshot = $this->buildSnapshot($companyId, $review, $bidders, $recommended, $narrative);
            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $id = DB::table('procgov_recommendations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'review_id' => $reviewId,
                'reference' => $reference,
                'version' => $version,
                'recommended_bidder_id' => $recommended->id,
                'recommended_supplier_name' => $recommended->supplier_name,
                'recommended_amount' => $recommended->bid_amount,
                'currency' => $recommended->currency,
                'committee_comments' => $narrative['committee_comments'] ?? null,
                'recommendation' => $narrative['recommendation'] ?? null,
                'award_conditions' => $narrative['award_conditions'] ?? null,
                'snapshot' => $snapshotJson,
                'snapshot_hash' => hash('sha256', (string) $snapshotJson),
                'status' => 'DRAFT',
                'generated_by' => auth()->id(),
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('procgov_bidders')->where('id', $recommended->id)->update([
                'status' => 'RECOMMENDED',
                'updated_at' => $now,
            ]);

            $this->audit->record($companyId, [
                'action' => 'recommendation_generated',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_recommendations',
                'entity_id' => $id,
                'entity_reference' => $reference,
                'record_id' => $review->record_id,
                'summary' => sprintf(
                    'Generated recommendation report %s v%d for %s — recommending %s at %s',
                    $reference,
                    $version,
                    $review->reference,
                    $recommended->supplier_name,
                    number_format((float) $recommended->bid_amount, 2),
                ),
                'after' => ['snapshot_hash' => hash('sha256', (string) $snapshotJson)],
            ]);

            return DB::table('procgov_recommendations')->find($id);
        });
    }

    /** Submit the report into the tenant's approval chain (§1.1's required approval level). */
    public function submitForApproval(int $companyId, int $recommendationId): ?object
    {
        $recommendation = DB::table('procgov_recommendations')->find($recommendationId);

        if (! $recommendation || $recommendation->status !== 'DRAFT') {
            throw new DomainRuleException('Only a draft recommendation can be submitted for approval.');
        }

        $review = DB::table('procgov_tender_reviews')->find($recommendation->review_id);

        $subject = new RuleSubject(
            companyId: $companyId,
            type: 'procgov_recommendations',
            id: $recommendationId,
            reference: $recommendation->reference,
            fields: (array) $recommendation,
            amount: $recommendation->recommended_amount !== null
                ? (float) $recommendation->recommended_amount
                : null,
            category: $review?->category,
            companyUnitId: $review?->company_unit_id ? (int) $review->company_unit_id : null,
            method: 'OPEN_TENDER',
            recordId: $review?->record_id ? (int) $review->record_id : null,
        );

        $request = null;

        try {
            $request = $this->approvals->start('tender_recommendation', $subject, [
                'label' => "Award recommendation: {$recommendation->recommended_supplier_name}",
                'record_id' => $review?->record_id,
            ]);
        } catch (\RuntimeException $e) {
            report($e);
        }

        DB::table('procgov_recommendations')->where('id', $recommendationId)->update([
            'status' => 'SUBMITTED',
            'submitted_at' => Carbon::now(),
            'approval_request_id' => $request?->id,
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'recommendation_submitted',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_recommendations',
            'entity_id' => $recommendationId,
            'entity_reference' => $recommendation->reference,
            'record_id' => $review?->record_id,
            'summary' => "Submitted recommendation {$recommendation->reference} for approval",
        ]);

        return $request;
    }

    /** Record the approver's decision and, on approval, mark the award. */
    public function decide(int $companyId, int $recommendationId, string $decision, ?string $comment = null): void
    {
        DB::transaction(function () use ($companyId, $recommendationId, $decision, $comment) {
            $recommendation = DB::table('procgov_recommendations')->lockForUpdate()->find($recommendationId);

            if (! $recommendation || $recommendation->status !== 'SUBMITTED') {
                throw new DomainRuleException('Only a submitted recommendation can be decided.');
            }

            $now = Carbon::now();
            $review = DB::table('procgov_tender_reviews')->find($recommendation->review_id);

            DB::table('procgov_recommendations')->where('id', $recommendationId)->update([
                'status' => $decision === 'APPROVED' ? 'APPROVED' : 'REJECTED',
                'decided_at' => $now,
                'decided_by' => auth()->id(),
                'decision_comment' => $comment,
                'updated_at' => $now,
            ]);

            if ($decision === 'APPROVED') {
                DB::table('procgov_bidders')->where('id', $recommendation->recommended_bidder_id)->update([
                    'status' => 'AWARDED',
                    'updated_at' => $now,
                ]);

                // Everyone else on the review is unsuccessful, except those already disqualified —
                // a disqualification is a different and more serious outcome and must not be
                // overwritten by a blanket update.
                DB::table('procgov_bidders')
                    ->where('review_id', $recommendation->review_id)
                    ->where('id', '!=', $recommendation->recommended_bidder_id)
                    ->where('is_disqualified', false)
                    ->update(['status' => 'UNSUCCESSFUL', 'updated_at' => $now]);

                DB::table('procgov_tender_reviews')->where('id', $recommendation->review_id)->update([
                    'status' => 'AWARDED',
                    'awarded_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($review?->record_id) {
                    DB::table('procgov_records')->where('id', $review->record_id)->update([
                        'awarded_value' => $recommendation->recommended_amount,
                        'updated_at' => $now,
                    ]);

                    $this->records->transition(
                        $companyId,
                        (int) $review->record_id,
                        'AWARD',
                        "Awarded to {$recommendation->recommended_supplier_name}",
                    );

                    $this->records->link($companyId, (int) $review->record_id, [
                        'link_role' => 'recommendation',
                        'entity_type' => 'procgov_recommendations',
                        'entity_id' => $recommendationId,
                        'entity_reference' => $recommendation->reference,
                        'label' => $recommendation->recommended_supplier_name,
                        'amount' => $recommendation->recommended_amount,
                        'state' => 'APPROVED',
                    ]);

                    /*
                     * Close the chain into the neighbouring modules straight away, so the awarded
                     * supplier appears on the 360° record the moment the award is made. Contracts
                     * and invoices arrive later, in other modules, and are picked up by the nightly
                     * reconciliation — see ChainLinker.
                     *
                     * Wrapped: an integration that cannot be reached must not roll back an award
                     * that has already been decided and audited.
                     */
                    try {
                        app(ChainLinker::class)->sync($companyId, (int) $review->record_id);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            $this->audit->record($companyId, [
                'action' => 'recommendation_'.mb_strtolower($decision),
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_recommendations',
                'entity_id' => $recommendationId,
                'entity_reference' => $recommendation->reference,
                'record_id' => $review?->record_id,
                'decision' => $decision,
                'comment' => $comment,
                'summary' => sprintf(
                    '%s award recommendation %s (%s)',
                    ucfirst(mb_strtolower($decision)),
                    $recommendation->reference,
                    $recommendation->recommended_supplier_name,
                ),
            ]);
        });
    }

    /** Render the stored snapshot to PDF. Never re-queries — see the class docblock. */
    public function pdf(int $companyId, int $recommendationId): array
    {
        $recommendation = DB::table('procgov_recommendations')
            ->where('company_id', $companyId)
            ->where('id', $recommendationId)
            ->first();

        if (! $recommendation) {
            throw new DomainRuleException('Recommendation not found.');
        }

        $snapshot = json_decode((string) $recommendation->snapshot, true) ?: [];

        // Approval history is genuinely open-ended after generation — an approver signs *after* the
        // report exists — so it is the one section read live and stamped as such in the document.
        $snapshot['approval_history'] = $this->approvalHistory($recommendation);
        $snapshot['rendered_at'] = Carbon::now()->toDayDateTimeString();
        $snapshot['integrity_hash'] = $recommendation->snapshot_hash;

        $this->audit->record($companyId, [
            'action' => 'recommendation_exported',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_recommendations',
            'entity_id' => $recommendationId,
            'entity_reference' => $recommendation->reference,
            'summary' => "Exported recommendation report {$recommendation->reference} to PDF",
        ]);

        $pdf = Pdf::loadView('pdf.procurement-governance.recommendation', ['report' => $snapshot])
            ->setPaper('a4', 'portrait');

        return [
            'pdf' => $pdf,
            'filename' => Str::slug($recommendation->reference.'-v'.$recommendation->version).'.pdf',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** Every one of §1.8's thirteen sections, resolved once and frozen. */
    private function buildSnapshot(
        int $companyId,
        object $review,
        Collection $bidders,
        object $recommended,
        array $narrative,
    ): array {
        $criteria = $this->scoring->criteriaTree($companyId, (int) $review->id);

        $evaluators = DB::table('procgov_review_evaluators as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('e.review_id', $review->id)
            ->get([
                'e.id', 'e.member_role', 'e.is_voting', 'e.is_external', 'e.external_name',
                'e.external_email', 'e.coi_status', 'e.confidentiality_accepted_at', 'e.status',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                'u.email as user_email',
            ])
            ->map(fn ($e) => [
                'name' => $e->is_external
                    ? $e->external_name
                    : (trim((string) $e->user_name) ?: $e->user_email),
                'role' => $e->member_role,
                'external' => (bool) $e->is_external,
                'voting' => (bool) $e->is_voting,
                'coi_status' => $e->coi_status,
                'confidentiality' => $e->confidentiality_accepted_at !== null,
                'status' => $e->status,
            ])
            ->all();

        $individualScores = DB::table('procgov_evaluations as ev')
            ->join('procgov_review_evaluators as e', 'e.id', '=', 'ev.evaluator_id')
            ->join('procgov_bidders as b', 'b.id', '=', 'ev.bidder_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('ev.review_id', $review->id)
            ->where('ev.status', 'SUBMITTED')
            ->get([
                'b.supplier_name', 'ev.total_raw_score', 'ev.total_weighted_score',
                'ev.threshold_passed', 'ev.revision', 'ev.submitted_at', 'ev.overall_comment',
                'e.is_external', 'e.external_name',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                'u.email as user_email',
            ])
            ->map(fn ($s) => [
                'evaluator' => $s->is_external
                    ? $s->external_name
                    : (trim((string) $s->user_name) ?: $s->user_email),
                'supplier' => $s->supplier_name,
                'raw_score' => $s->total_raw_score,
                'weighted_score' => $s->total_weighted_score,
                'threshold_passed' => $s->threshold_passed,
                'revision' => $s->revision,
                'submitted_at' => $s->submitted_at,
                'comment' => $s->overall_comment,
            ])
            ->all();

        $committee = $review->committee_id
            ? DB::table('procgov_committees')->find($review->committee_id)
            : null;

        return [
            'generated_at' => Carbon::now()->toDayDateTimeString(),
            'generated_by' => auth()->user()?->email,

            'tender' => [
                'review_reference' => $review->reference,
                'tender_number' => $review->tender_number,
                'title' => $review->title,
                'description' => $review->description,
                'category' => $review->category,
                'methodology' => $review->methodology,
                'price_weight' => $review->price_weight,
                'preference_weight' => $review->preference_weight,
                'estimated_value' => $review->estimated_value,
                'currency' => $review->currency,
                'closing_at' => $review->tender_closing_at,
                'evaluation_start' => $review->evaluation_start_date,
                'evaluation_deadline' => $review->evaluation_deadline,
                'criteria_frozen_at' => $review->criteria_frozen_at,
                'required_approval_level' => $review->required_approval_level,
            ],

            'committee' => $committee ? [
                'reference' => $committee->reference,
                'name' => $committee->name,
                'type' => $committee->committee_type,
                'appointment_date' => $committee->appointment_date,
                'quorum' => $committee->quorum,
            ] : null,

            'members' => $evaluators,

            'criteria' => $criteria['all']->map(fn ($c) => [
                'name' => $c->name,
                'group' => $c->criterion_group,
                'parent' => $c->parent_id,
                'weight' => $c->weight,
                'effective_weight' => $c->effective_weight,
                'max_score' => $c->max_score,
                'scoring_method' => $c->scoring_method,
                'is_mandatory' => (bool) $c->is_mandatory,
                'minimum_threshold' => $c->minimum_threshold,
            ])->values()->all(),

            'suppliers' => $bidders->map(fn ($b) => [
                'name' => $b->supplier_name,
                'registration' => $b->registration_number,
                'bid_reference' => $b->bid_reference,
                'bid_amount' => $b->bid_amount,
                'currency' => $b->currency,
                'submitted_at' => $b->submitted_at,
                'is_late' => (bool) $b->is_late,
                'bbbee_level' => $b->bbbee_level,
                'technical_score' => $b->technical_score,
                'financial_score' => $b->financial_score,
                'preference_score' => $b->preference_score,
                'average_score' => $b->average_score,
                'weighted_score' => $b->weighted_score,
                'threshold_passed' => $b->threshold_passed,
                'financial_rank' => $b->financial_rank,
                'overall_rank' => $b->overall_rank,
                'status' => $b->status,
            ])->values()->all(),

            'individual_scores' => $individualScores,

            'disqualifications' => $bidders->where('is_disqualified', true)->map(fn ($b) => [
                'name' => $b->supplier_name,
                'stage' => $b->disqualification_stage,
                'reason' => $b->disqualification_reason,
                'at' => $b->disqualified_at,
            ])->values()->all(),

            'recommendation' => [
                'supplier' => $recommended->supplier_name,
                'amount' => $recommended->bid_amount,
                'currency' => $recommended->currency,
                'overall_rank' => $recommended->overall_rank,
                'weighted_score' => $recommended->weighted_score,
                'narrative' => $narrative['recommendation'] ?? null,
                'committee_comments' => $narrative['committee_comments'] ?? null,
                'award_conditions' => $narrative['award_conditions'] ?? null,
            ],

            'moderation' => DB::table('procgov_moderation_items as i')
                ->join('procgov_moderations as m', 'm.id', '=', 'i.moderation_id')
                ->leftJoin('procgov_bidders as b', 'b.id', '=', 'i.bidder_id')
                ->leftJoin('procgov_criteria as c', 'c.id', '=', 'i.criterion_id')
                ->where('m.review_id', $review->id)
                ->get(['i.item_type', 'i.variance', 'i.action', 'i.moderator_comment',
                    'i.evaluator_response', 'i.status', 'b.supplier_name', 'c.name as criterion'])
                ->map(fn ($i) => (array) $i)
                ->all(),

            'documents' => DB::table('procgov_document_links as l')
                ->join('procgov_documents as d', 'd.id', '=', 'l.document_id')
                ->where('l.entity_type', 'procgov_tender_reviews')
                ->where('l.entity_id', $review->id)
                ->whereNull('d.deleted_at')
                ->get(['d.reference', 'd.title', 'd.confidentiality', 'd.version_count', 'l.link_role'])
                ->map(fn ($d) => (array) $d)
                ->all(),

            'compliance' => DB::table('procgov_compliance_checks')
                ->where('subject_type', 'procgov_tender_reviews')
                ->where('subject_id', $review->id)
                ->orderByDesc('checked_at')
                ->limit(5)
                ->get(['result', 'rules_evaluated', 'failures', 'warnings', 'checked_at',
                    'rule_set_code', 'rule_set_version'])
                ->map(fn ($c) => (array) $c)
                ->all(),

            // §1.8 "audit information": the count and the chain position, so the report states what
            // exists without embedding a hundred pages of log.
            'audit' => [
                'events' => DB::table('procgov_audit_events')
                    ->where('company_id', $companyId)
                    ->where('record_id', $review->record_id)
                    ->count(),
                'first_sequence' => DB::table('procgov_audit_events')
                    ->where('company_id', $companyId)
                    ->where('record_id', $review->record_id)
                    ->min('sequence'),
                'latest_sequence' => DB::table('procgov_audit_events')
                    ->where('company_id', $companyId)
                    ->where('record_id', $review->record_id)
                    ->max('sequence'),
            ],
        ];
    }

    private function approvalHistory(object $recommendation): array
    {
        if (! $recommendation->approval_request_id) {
            return [];
        }

        return DB::table('procgov_approval_steps as s')
            ->leftJoin('users as u', 'u.id', '=', 's.decided_by')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('s.request_id', $recommendation->approval_request_id)
            ->orderBy('s.step_no')
            ->get([
                's.step_no', 's.name', 's.approver_label', 's.status', 's.decision',
                's.decided_at', 's.comment',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as decided_by_name"),
                'u.email as decided_by_email',
            ])
            ->map(fn ($s) => [
                'step' => $s->step_no,
                'name' => $s->name,
                'approver' => $s->approver_label,
                'status' => $s->status,
                'decision' => $s->decision,
                'decided_at' => $s->decided_at,
                'decided_by' => trim((string) $s->decided_by_name) ?: $s->decided_by_email,
                'comment' => $s->comment,
            ])
            ->all();
    }
}
