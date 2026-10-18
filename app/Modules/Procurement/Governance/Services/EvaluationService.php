<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The individual evaluator's workspace (§1.5) and the immutability rules around it (§1.5, §1.6).
 *
 * An evaluator sees only their own scores. That is not a nicety: evaluators who can see each
 * other's marks converge on them, and the independent assessment the whole committee model rests on
 * quietly stops existing. `workspace()` therefore scopes to the calling evaluator, and the
 * comparative view lives in moderation, where the chairperson is meant to see it.
 *
 * Submission locks the evaluation. A correction afterwards is not an edit — it is a reopening, with
 * a reason, which archives the prior revision's scores before anything changes. §1.6 requires the
 * original scores to survive an authorised correction, and the only way that is true is if the
 * correction path is physically incapable of overwriting them.
 */
class EvaluationService
{
    public function __construct(
        private readonly ScoringEngine $scoring,
        private readonly ConflictOfInterestService $coi,
        private readonly AuditLedger $audit,
        private readonly SettingsService $settings,
    ) {}

    /**
     * One evaluator's workspace for one bidder: the criteria, their own scores, and what they may
     * do next. Creates the evaluation shell on first open so a draft always has somewhere to live.
     *
     * @return array<string,mixed>
     */
    public function workspace(int $companyId, int $reviewId, int $evaluatorId, int $bidderId): array
    {
        $evaluator = DB::table('procgov_review_evaluators')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->where('id', $evaluatorId)
            ->first();

        if (! $evaluator) {
            throw new DomainRuleException('You are not assigned to this tender review.');
        }

        if (! $this->coi->mayEvaluate($evaluator)) {
            throw new DomainRuleException(
                'You cannot score this tender until your conflict-of-interest declaration is '
                .'completed and, where a conflict was declared, resolved.'
            );
        }

        $evaluation = $this->ensureEvaluation($companyId, $reviewId, $evaluatorId, $bidderId);
        $criteria = $this->scoring->criteriaTree($companyId, $reviewId);

        $scores = DB::table('procgov_evaluation_scores')
            ->where('evaluation_id', $evaluation->id)
            ->get()
            ->keyBy('criterion_id');

        $review = DB::table('procgov_tender_reviews')->find($reviewId);
        $bidder = DB::table('procgov_bidders')->find($bidderId);

        return [
            'evaluation' => $evaluation,
            'review' => $review,
            'bidder' => $bidder,
            'criteria' => $criteria['all']->map(function ($c) use ($scores) {
                $score = $scores[$c->id] ?? null;
                $c->my_score = $score?->score;
                $c->my_comment = $score?->comment;
                $c->my_passed = $score?->passed;
                $c->my_flagged = (bool) ($score?->flagged ?? false);
                $c->is_leaf = ($c->children ?? collect())->isEmpty();
                $c->scoring_bands = $c->scoring_bands ? json_decode((string) $c->scoring_bands, true) : null;
                unset($c->children);

                return $c;
            })->values(),
            'editable' => $this->isEditable($evaluation),
            // Deliberately absent: any other evaluator's scores. See the class docblock.
        ];
    }

    /**
     * Save a draft. Scores arrive keyed by criterion id.
     *
     * @param  array<int,array{score?:float|null, comment?:string|null, passed?:bool|null, flagged?:bool}>  $scores
     */
    public function saveDraft(int $companyId, int $evaluationId, array $scores, array $meta = []): object
    {
        $evaluation = DB::table('procgov_evaluations')->find($evaluationId);

        if (! $evaluation) {
            throw new DomainRuleException('Evaluation not found.');
        }

        if (! $this->isEditable($evaluation)) {
            throw new DomainRuleException(
                'This evaluation has been submitted and is locked. A correction requires an '
                .'authorised reopening, which records the reason and preserves the original scores.'
            );
        }

        $criteria = DB::table('procgov_criteria')
            ->where('review_id', $evaluation->review_id)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        DB::transaction(function () use ($companyId, $evaluation, $scores, $criteria, $meta) {
            $now = Carbon::now();

            foreach ($scores as $criterionId => $values) {
                $criterion = $criteria[$criterionId] ?? null;

                if (! $criterion) {
                    continue;   // a criterion from another review, or one since removed
                }

                $score = $values['score'] ?? null;
                $max = (float) $criterion->max_score;

                // Clamp rather than reject: a slider that lets you enter 105 out of 100 and then
                // refuses the whole save loses the other nineteen criteria the evaluator just did.
                if ($score !== null) {
                    $score = max(0, min($max, (float) $score));
                }

                DB::table('procgov_evaluation_scores')->updateOrInsert(
                    ['evaluation_id' => $evaluation->id, 'criterion_id' => $criterionId],
                    [
                        'company_id' => $companyId,
                        'score' => $score,
                        'max_score' => $max,
                        'passed' => $values['passed'] ?? null,
                        'comment' => $values['comment'] ?? null,
                        'flagged' => $values['flagged'] ?? false,
                        'evidence_document_id' => $values['evidence_document_id'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }

            DB::table('procgov_evaluations')->where('id', $evaluation->id)->update([
                'overall_comment' => $meta['overall_comment'] ?? $evaluation->overall_comment,
                'has_concerns' => $meta['has_concerns'] ?? $evaluation->has_concerns,
                'concern_note' => $meta['concern_note'] ?? $evaluation->concern_note,
                'status' => $evaluation->status === 'NOT_STARTED' ? 'DRAFT' : $evaluation->status,
                'updated_at' => $now,
            ]);
        });

        $this->scoring->computeEvaluation($companyId, $evaluationId);

        return DB::table('procgov_evaluations')->find($evaluationId);
    }

    /**
     * Submit. After this the evaluation is immutable except through `reopen()`.
     *
     * @return list<string> problems preventing submission; empty when submitted
     */
    public function submit(int $companyId, int $evaluationId): array
    {
        $evaluation = DB::table('procgov_evaluations')->find($evaluationId);

        if (! $evaluation) {
            return ['Evaluation not found.'];
        }

        if ($evaluation->status === 'SUBMITTED') {
            return [];
        }

        $criteria = $this->scoring->criteriaTree($companyId, (int) $evaluation->review_id);

        $scores = DB::table('procgov_evaluation_scores')
            ->where('evaluation_id', $evaluationId)
            ->get()
            ->keyBy('criterion_id');

        $missing = [];

        foreach ($criteria['leaves'] as $criterion) {
            $score = $scores[$criterion->id] ?? null;

            $unscored = $criterion->is_pass_fail
                ? ($score === null || $score->passed === null)
                : ($score === null || $score->score === null);

            if ($unscored) {
                $missing[] = $criterion->name;
            }
        }

        if ($missing !== []) {
            return ['Every criterion must be scored before submitting. Outstanding: '.implode(', ', $missing).'.'];
        }

        DB::transaction(function () use ($companyId, $evaluation, $evaluationId) {
            $now = Carbon::now();

            DB::table('procgov_evaluations')->where('id', $evaluationId)->update([
                'status' => 'SUBMITTED',
                'is_locked' => true,
                'submitted_at' => $now,
                'submitted_ip' => request()->ip(),
                'updated_at' => $now,
            ]);

            // The evaluator is done once they have submitted for every bidder they were given.
            $outstanding = DB::table('procgov_evaluations')
                ->where('evaluator_id', $evaluation->evaluator_id)
                ->whereNotIn('status', ['SUBMITTED', 'WITHDRAWN', 'SUPERSEDED'])
                ->count();

            DB::table('procgov_review_evaluators')->where('id', $evaluation->evaluator_id)->update([
                'status' => $outstanding === 0 ? 'SUBMITTED' : 'IN_PROGRESS',
                'completed_at' => $outstanding === 0 ? $now : null,
                'updated_at' => $now,
            ]);

            $review = DB::table('procgov_tender_reviews')->find($evaluation->review_id);
            $bidder = DB::table('procgov_bidders')->find($evaluation->bidder_id);

            $this->audit->record($companyId, [
                'action' => 'evaluation_submitted',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_evaluations',
                'entity_id' => $evaluationId,
                'entity_reference' => $review?->reference,
                'record_id' => $review?->record_id,
                'summary' => "Submitted evaluation of {$bidder?->supplier_name} on {$review?->reference}",
                'after' => [
                    'total_weighted_score' => $evaluation->total_weighted_score,
                    'revision' => $evaluation->revision,
                ],
            ]);
        });

        $this->scoring->computeEvaluation($companyId, $evaluationId);
        $this->scoring->computeReview($companyId, (int) $evaluation->review_id);

        return [];
    }

    /**
     * §1.5/§1.6: the authorised correction path. Archives the current revision's scores, bumps the
     * revision, and unlocks — the original scores remain readable in
     * `procgov_evaluation_score_history` forever.
     */
    public function reopen(int $companyId, int $evaluationId, string $reason, ?int $approvalRequestId = null): void
    {
        if (trim($reason) === '') {
            throw new DomainRuleException('Reopening a submitted evaluation requires a reason.');
        }

        if (! $this->settings->get($companyId)->allow_evaluation_reopen) {
            throw new DomainRuleException(
                'Your organisation has disabled reopening submitted evaluations. A correction must '
                .'be handled through moderation instead.'
            );
        }

        DB::transaction(function () use ($companyId, $evaluationId, $reason, $approvalRequestId) {
            $evaluation = DB::table('procgov_evaluations')->lockForUpdate()->find($evaluationId);

            if (! $evaluation || $evaluation->status !== 'SUBMITTED') {
                throw new DomainRuleException('Only a submitted evaluation can be reopened.');
            }

            $now = Carbon::now();

            $scores = DB::table('procgov_evaluation_scores')
                ->where('evaluation_id', $evaluationId)
                ->get();

            foreach ($scores as $score) {
                DB::table('procgov_evaluation_score_history')->insert([
                    'company_id' => $companyId,
                    'evaluation_id' => $evaluationId,
                    'criterion_id' => $score->criterion_id,
                    'revision' => $evaluation->revision,
                    'score' => $score->score,
                    'max_score' => $score->max_score,
                    'weight_applied' => $score->weight_applied,
                    'weighted_score' => $score->weighted_score,
                    'passed' => $score->passed,
                    'comment' => $score->comment,
                    'reason' => Str::limit($reason, 495, ''),
                    'archived_by' => auth()->id(),
                    'archived_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('procgov_evaluations')->where('id', $evaluationId)->update([
                'status' => 'REOPENED',
                'is_locked' => false,
                'revision' => $evaluation->revision + 1,
                'reopened_at' => $now,
                'reopened_by' => auth()->id(),
                'reopen_reason' => $reason,
                'reopen_approval_request_id' => $approvalRequestId,
                'updated_at' => $now,
            ]);

            $review = DB::table('procgov_tender_reviews')->find($evaluation->review_id);

            $this->audit->record($companyId, [
                'action' => 'evaluation_reopened',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_evaluations',
                'entity_id' => $evaluationId,
                'entity_reference' => $review?->reference,
                'record_id' => $review?->record_id,
                'comment' => $reason,
                'before' => [
                    'revision' => $evaluation->revision,
                    'total_weighted_score' => $evaluation->total_weighted_score,
                ],
                'summary' => "Reopened a submitted evaluation on {$review?->reference} (revision "
                    .($evaluation->revision + 1).')',
            ]);
        });

        $evaluation = DB::table('procgov_evaluations')->find($evaluationId);
        $this->scoring->computeReview($companyId, (int) $evaluation->review_id);
    }

    /** What one evaluator still has to do — the "my evaluations" list. */
    public function myWork(int $companyId, int $userId): Collection
    {
        return DB::table('procgov_review_evaluators as e')
            ->join('procgov_tender_reviews as r', 'r.id', '=', 'e.review_id')
            ->where('e.company_id', $companyId)
            ->where('e.user_id', $userId)
            ->whereNotIn('e.status', ['REMOVED'])
            ->whereIn('r.status', ['EVALUATION', 'MODERATION', 'CRITERIA_SETUP', 'COMMITTEE_SETUP'])
            ->whereNull('r.deleted_at')
            ->orderBy('r.evaluation_deadline')
            ->get([
                'e.id as evaluator_id', 'e.uuid as evaluator_uuid', 'e.coi_status', 'e.status as my_status',
                'e.confidentiality_accepted_at',
                'r.id as review_id', 'r.uuid as review_uuid', 'r.reference', 'r.title', 'r.status',
                'r.evaluation_deadline', 'r.tender_closing_at', 'r.methodology',
            ])
            ->map(function ($row) {
                $row->bidders = DB::table('procgov_bidders')
                    ->where('review_id', $row->review_id)
                    ->whereNull('deleted_at')
                    ->where('is_disqualified', false)
                    ->count();
                $row->submitted = DB::table('procgov_evaluations')
                    ->where('evaluator_id', $row->evaluator_id)
                    ->where('status', 'SUBMITTED')
                    ->count();
                $row->overdue = $row->evaluation_deadline
                    && Carbon::parse($row->evaluation_deadline)->isPast()
                    && $row->submitted < $row->bidders;

                return $row;
            });
    }

    /** The archived revisions of an evaluation — §1.6's guarantee, made visible. */
    public function history(int $companyId, int $evaluationId): Collection
    {
        return DB::table('procgov_evaluation_score_history as h')
            ->leftJoin('procgov_criteria as c', 'c.id', '=', 'h.criterion_id')
            ->where('h.company_id', $companyId)
            ->where('h.evaluation_id', $evaluationId)
            ->orderByDesc('h.revision')
            ->orderBy('c.sort_order')
            ->get([
                'h.revision', 'h.score', 'h.max_score', 'h.weighted_score', 'h.passed',
                'h.comment', 'h.reason', 'h.archived_at', 'c.name as criterion_name',
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function ensureEvaluation(int $companyId, int $reviewId, int $evaluatorId, int $bidderId): object
    {
        $existing = DB::table('procgov_evaluations')
            ->where('evaluator_id', $evaluatorId)
            ->where('bidder_id', $bidderId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $now = Carbon::now();

        $id = DB::table('procgov_evaluations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'review_id' => $reviewId,
            'evaluator_id' => $evaluatorId,
            'bidder_id' => $bidderId,
            'status' => 'DRAFT',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('procgov_evaluations')->find($id);
    }

    private function isEditable(object $evaluation): bool
    {
        return ! $evaluation->is_locked
            && in_array($evaluation->status, ['NOT_STARTED', 'DRAFT', 'REOPENED'], true);
    }
}
