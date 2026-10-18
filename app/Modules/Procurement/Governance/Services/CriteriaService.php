<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Evaluation criteria (§1.4): weighted, nestable, thresholded, and frozen once scoring begins.
 *
 * The freeze is the whole point. Criteria that can move after evaluators have scored against them
 * allow a tender to be steered retroactively — change the weighting, and a different bidder wins
 * with nobody's scores having changed. So once `criteria_frozen_at` is set, every write here goes
 * through `requestChange()`: a recorded proposal, with a reason, that must be approved before it
 * applies, and which flags whether re-scoring is required.
 *
 * Scoring methods are per criterion rather than per tender, because a real evaluation matrix mixes
 * them — a pass/fail on tax compliance, banded points on years of experience, a price formula on
 * cost — and forcing one method on all of them is what pushes people back into spreadsheets.
 */
class CriteriaService
{
    public const GROUPS = ['TECHNICAL', 'FINANCIAL', 'EXPERIENCE', 'PREFERENCE', 'COMPLIANCE'];

    public const SCORING_METHODS = ['POINTS', 'PERCENTAGE', 'PASS_FAIL', 'BAND', 'FORMULA'];

    public function __construct(
        private readonly AuditLedger $audit,
        private readonly ScoringEngine $scoring,
        private readonly ApprovalRouter $approvals,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws \RuntimeException once the criteria are frozen
     */
    public function add(int $companyId, int $reviewId, array $data): object
    {
        $this->assertNotFrozen($companyId, $reviewId);

        $now = Carbon::now();

        $id = DB::table('procgov_criteria')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'review_id' => $reviewId,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'criterion_group' => $data['criterion_group'] ?? 'TECHNICAL',
            'weight' => $data['weight'] ?? 0,
            'max_score' => $data['max_score'] ?? 100,
            'scoring_method' => $data['scoring_method'] ?? 'POINTS',
            'scoring_bands' => isset($data['scoring_bands']) ? json_encode($data['scoring_bands']) : null,
            'formula' => $data['formula'] ?? null,
            'is_mandatory' => $data['is_mandatory'] ?? false,
            'is_pass_fail' => ($data['scoring_method'] ?? null) === 'PASS_FAIL' || ($data['is_pass_fail'] ?? false),
            'minimum_threshold' => $data['minimum_threshold'] ?? null,
            'scored_by_roles' => isset($data['scored_by_roles']) ? json_encode($data['scored_by_roles']) : null,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->record($companyId, [
            'action' => 'criterion_added',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_criteria',
            'entity_id' => $id,
            'summary' => "Added criterion \"{$data['name']}\" at {$data['weight']}%",
            'after' => ['name' => $data['name'], 'weight' => $data['weight'] ?? 0],
        ]);

        return DB::table('procgov_criteria')->find($id);
    }

    /** @param  array<string,mixed>  $data */
    public function update(int $companyId, int $criterionId, array $data): object
    {
        $criterion = DB::table('procgov_criteria')->find($criterionId);

        if (! $criterion) {
            throw new DomainRuleException('Criterion not found.');
        }

        $this->assertNotFrozen($companyId, (int) $criterion->review_id);

        if (isset($data['scoring_bands'])) {
            $data['scoring_bands'] = json_encode($data['scoring_bands']);
        }

        if (isset($data['scored_by_roles'])) {
            $data['scored_by_roles'] = json_encode($data['scored_by_roles']);
        }

        $before = array_intersect_key((array) $criterion, $data);

        DB::table('procgov_criteria')->where('id', $criterionId)->update(
            array_merge($data, ['updated_at' => Carbon::now()])
        );

        $this->audit->record($companyId, [
            'action' => 'criterion_updated',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_criteria',
            'entity_id' => $criterionId,
            'before' => $before,
            'after' => $data,
        ]);

        return DB::table('procgov_criteria')->find($criterionId);
    }

    public function remove(int $companyId, int $criterionId): void
    {
        $criterion = DB::table('procgov_criteria')->find($criterionId);

        if (! $criterion) {
            return;
        }

        $this->assertNotFrozen($companyId, (int) $criterion->review_id);

        DB::table('procgov_criteria')->where('id', $criterionId)->update([
            'deleted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'criterion_removed',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_criteria',
            'entity_id' => $criterionId,
            'before' => ['name' => $criterion->name, 'weight' => $criterion->weight],
        ]);
    }

    /**
     * Copy a criteria set from an earlier review. Most organisations evaluate the same categories
     * the same way, and retyping a 20-line matrix is where transcription errors come from.
     */
    public function copyFrom(int $companyId, int $targetReviewId, int $sourceReviewId): int
    {
        $this->assertNotFrozen($companyId, $targetReviewId);

        $source = DB::table('procgov_criteria')
            ->where('company_id', $companyId)
            ->where('review_id', $sourceReviewId)
            ->whereNull('deleted_at')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->get();

        $idMap = [];
        $copied = 0;
        $now = Carbon::now();

        foreach ($source as $criterion) {
            $newId = DB::table('procgov_criteria')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'review_id' => $targetReviewId,
                // Parents are inserted before children by the ordering above, so the mapped parent
                // always exists by the time a child needs it.
                'parent_id' => $criterion->parent_id ? ($idMap[$criterion->parent_id] ?? null) : null,
                'code' => $criterion->code,
                'name' => $criterion->name,
                'description' => $criterion->description,
                'criterion_group' => $criterion->criterion_group,
                'weight' => $criterion->weight,
                'max_score' => $criterion->max_score,
                'scoring_method' => $criterion->scoring_method,
                'scoring_bands' => $criterion->scoring_bands,
                'formula' => $criterion->formula,
                'is_mandatory' => $criterion->is_mandatory,
                'is_pass_fail' => $criterion->is_pass_fail,
                'minimum_threshold' => $criterion->minimum_threshold,
                'scored_by_roles' => $criterion->scored_by_roles,
                'sort_order' => $criterion->sort_order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $idMap[$criterion->id] = $newId;
            $copied++;
        }

        $this->audit->record($companyId, [
            'action' => 'criteria_copied',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_tender_reviews',
            'entity_id' => $targetReviewId,
            'summary' => "Copied {$copied} criteria from review #{$sourceReviewId}",
        ]);

        return $copied;
    }

    /**
     * §1.4's "authorised change process": propose a change to frozen criteria. It does not apply
     * until approved, and applying it archives any scores it invalidates rather than leaving stale
     * numbers against a criterion that has since moved.
     *
     * @param  array<string,mixed>  $changes
     */
    public function requestChange(
        int $companyId,
        int $reviewId,
        ?int $criterionId,
        string $changeType,
        array $changes,
        string $reason,
    ): object {
        if (trim($reason) === '') {
            throw new DomainRuleException('A criteria change must record why it is needed.');
        }

        $before = $criterionId ? (array) DB::table('procgov_criteria')->find($criterionId) : null;
        $now = Carbon::now();

        $id = DB::table('procgov_criteria_changes')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'review_id' => $reviewId,
            'criterion_id' => $criterionId,
            'change_type' => $changeType,
            'before' => $before ? json_encode(array_intersect_key($before, $changes)) : null,
            'after' => json_encode($changes),
            'reason' => $reason,
            'status' => 'PENDING',
            'requested_by' => auth()->id(),
            'requested_at' => $now,
            'requires_rescoring' => $this->wouldInvalidateScores($changeType, $changes),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $review = DB::table('procgov_tender_reviews')->find($reviewId);

        // Route it through the tenant's chain when they have one for criteria changes; if not, the
        // change still exists as a recorded, approvable request rather than an invisible edit.
        try {
            $request = $this->approvals->start('criteria_change', new RuleSubject(
                companyId: $companyId,
                type: 'procgov_criteria_changes',
                id: $id,
                reference: $review?->reference,
                fields: $changes,
                amount: $review?->estimated_value !== null ? (float) $review->estimated_value : null,
                category: $review?->category,
                companyUnitId: $review?->company_unit_id ? (int) $review->company_unit_id : null,
                recordId: $review?->record_id ? (int) $review->record_id : null,
            ), ['label' => 'Criteria change on '.($review?->reference ?? "review #{$reviewId}")]);

            DB::table('procgov_criteria_changes')->where('id', $id)->update([
                'approval_request_id' => $request->id,
                'updated_at' => $now,
            ]);
        } catch (\RuntimeException $e) {
            report($e);
        }

        $this->audit->record($companyId, [
            'action' => 'criteria_change_requested',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_criteria_changes',
            'entity_id' => $id,
            'entity_reference' => $review?->reference,
            'record_id' => $review?->record_id,
            'comment' => $reason,
            'after' => $changes,
            'summary' => "Requested a {$changeType} change to frozen evaluation criteria",
        ]);

        return DB::table('procgov_criteria_changes')->find($id);
    }

    /** Apply an approved change, archiving any scores it invalidates. */
    public function applyChange(int $companyId, int $changeId): void
    {
        DB::transaction(function () use ($companyId, $changeId) {
            $change = DB::table('procgov_criteria_changes')->lockForUpdate()->find($changeId);

            if (! $change || $change->status !== 'APPROVED') {
                throw new DomainRuleException('Only an approved criteria change can be applied.');
            }

            $after = json_decode((string) $change->after, true) ?: [];
            $now = Carbon::now();

            if ($change->change_type === 'ADDED') {
                DB::table('procgov_criteria')->insert(array_merge([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'review_id' => $change->review_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $after));
            } elseif ($change->change_type === 'REMOVED' && $change->criterion_id) {
                DB::table('procgov_criteria')->where('id', $change->criterion_id)->update([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($change->criterion_id) {
                DB::table('procgov_criteria')->where('id', $change->criterion_id)->update(
                    array_merge($after, ['updated_at' => $now])
                );
            }

            if ($change->requires_rescoring && $change->criterion_id) {
                $this->archiveScoresFor($companyId, (int) $change->criterion_id, (string) $change->reason);
            }

            DB::table('procgov_criteria_changes')->where('id', $changeId)->update([
                'status' => 'APPLIED',
                'updated_at' => $now,
            ]);

            $this->scoring->computeReview($companyId, (int) $change->review_id);

            $this->audit->record($companyId, [
                'action' => 'criteria_change_applied',
                'capability' => 'tender_evaluation',
                'entity_type' => 'procgov_criteria_changes',
                'entity_id' => $changeId,
                'after' => $after,
                'summary' => 'Applied an approved change to frozen evaluation criteria',
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function assertNotFrozen(int $companyId, int $reviewId): void
    {
        $frozenAt = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->where('id', $reviewId)
            ->value('criteria_frozen_at');

        if ($frozenAt) {
            throw new DomainRuleException(
                'The evaluation criteria were frozen when evaluation opened and cannot be edited '
                .'directly. Raise a criteria change request, which records the reason and must be '
                .'approved before it applies.'
            );
        }
    }

    /**
     * A weight or maximum change alters what every existing score meant; renaming a criterion or
     * fixing its description does not. Only the first kind forces re-scoring, because forcing it
     * for a typo correction would be a reason people avoid correcting typos.
     */
    private function wouldInvalidateScores(string $changeType, array $changes): bool
    {
        if ($changeType !== 'AMENDED') {
            return true;
        }

        $material = ['weight', 'max_score', 'scoring_method', 'minimum_threshold', 'is_mandatory', 'is_pass_fail', 'formula'];

        return array_intersect(array_keys($changes), $material) !== [];
    }

    /** §1.6: the original scores are preserved, never overwritten. */
    private function archiveScoresFor(int $companyId, int $criterionId, string $reason): void
    {
        $scores = DB::table('procgov_evaluation_scores as s')
            ->join('procgov_evaluations as e', 'e.id', '=', 's.evaluation_id')
            ->where('s.criterion_id', $criterionId)
            ->get(['s.*', 'e.revision']);

        $now = Carbon::now();

        foreach ($scores as $score) {
            DB::table('procgov_evaluation_score_history')->insert([
                'company_id' => $companyId,
                'evaluation_id' => $score->evaluation_id,
                'criterion_id' => $score->criterion_id,
                'revision' => $score->revision,
                'score' => $score->score,
                'max_score' => $score->max_score,
                'weight_applied' => $score->weight_applied,
                'weighted_score' => $score->weighted_score,
                'passed' => $score->passed,
                'comment' => $score->comment,
                'reason' => Str::limit('Criteria change: '.$reason, 495, ''),
                'archived_by' => auth()->id(),
                'archived_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Clear the values but keep the rows, so each evaluator sees exactly which criterion they
        // must re-score rather than a blank workspace.
        DB::table('procgov_evaluation_scores')->where('criterion_id', $criterionId)->update([
            'score' => null,
            'weighted_score' => null,
            'passed' => null,
            'updated_at' => $now,
        ]);

        DB::table('procgov_evaluations')
            ->whereIn('id', $scores->pluck('evaluation_id')->unique())
            ->update([
                'status' => 'REOPENED',
                'is_locked' => false,
                'updated_at' => $now,
            ]);
    }
}
