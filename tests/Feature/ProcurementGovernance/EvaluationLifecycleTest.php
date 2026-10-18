<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Services\ConflictOfInterestService;
use App\Modules\Procurement\Governance\Services\CriteriaService;
use App\Modules\Procurement\Governance\Services\EvaluationService;
use App\Modules\Procurement\Governance\Services\TenderReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The §1 guarantees that make an evaluation defensible: nobody scores before declaring, criteria
 * freeze the moment scoring starts, a submitted evaluation is immutable, and an authorised
 * correction preserves what was originally scored.
 *
 * Each of these is a way a tender can be steered after the fact while every screen still looks
 * normal, which is precisely why they are enforced rather than documented.
 */
class EvaluationLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $reviewId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'evaluator-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->userId));

        $this->reviewId = DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-TEST-'.Str::random(6),
            'title' => 'Security services',
            'methodology' => 'WEIGHTED',
            'status' => 'CRITERIA_SETUP',
            'currency' => 'ZAR',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reviews(): TenderReviewService
    {
        return app(TenderReviewService::class);
    }

    private function evaluations(): EvaluationService
    {
        return app(EvaluationService::class);
    }

    private function coi(): ConflictOfInterestService
    {
        return app(ConflictOfInterestService::class);
    }

    private function criterion(float $weight = 100, array $attributes = []): int
    {
        return DB::table('procgov_criteria')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'name' => 'Technical',
            'criterion_group' => 'TECHNICAL',
            'weight' => $weight,
            'max_score' => 100,
            'scoring_method' => 'POINTS',
            'is_mandatory' => false,
            'is_pass_fail' => false,
            'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function bidder(string $name = 'Acme'): int
    {
        return DB::table('procgov_bidders')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'supplier_name' => $name,
            'bid_amount' => 500_000,
            'currency' => 'ZAR',
            'is_disqualified' => false,
            'status' => 'SUBMITTED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function evaluator(string $coiStatus = 'DECLARED'): int
    {
        return DB::table('procgov_review_evaluators')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'user_id' => $this->userId,
            'is_external' => false,
            'member_role' => 'MEMBER',
            'is_voting' => true,
            'coi_status' => $coiStatus,
            'status' => 'ASSIGNED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── §1.3 the conflict-of-interest gate ──────────────────────────────────────────────────────

    public function test_evaluation_cannot_open_while_a_declaration_is_outstanding(): void
    {
        $this->criterion();
        $this->bidder();
        $this->evaluator('NOT_COMPLETED');

        $problems = $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('conflict-of-interest', implode(' ', $problems));
        $this->assertNull(DB::table('procgov_tender_reviews')->find($this->reviewId)->criteria_frozen_at);
    }

    public function test_evaluation_cannot_open_while_a_declared_conflict_is_unresolved(): void
    {
        $this->criterion();
        $this->bidder();
        $this->evaluator('CONFLICT_IDENTIFIED');

        $problems = $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $this->assertStringContainsString('unresolved', implode(' ', $problems));
    }

    public function test_a_conflicted_evaluator_cannot_open_a_scoring_workspace(): void
    {
        $this->criterion();
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator('CONFLICT_IDENTIFIED');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/conflict-of-interest declaration/');

        $this->evaluations()->workspace($this->companyId, $this->reviewId, $evaluatorId, $bidder);
    }

    public function test_declaring_a_conflict_records_it_and_freezes_the_evaluator(): void
    {
        $this->criterion();
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator('NOT_COMPLETED');

        $this->coi()->declare($this->companyId, $evaluatorId, [
            'has_conflict' => true,
            'nature_of_interest' => 'My spouse is a director of the bidder.',
            'related_bidder_id' => $bidder,
        ]);

        $evaluator = DB::table('procgov_review_evaluators')->find($evaluatorId);

        $this->assertSame('CONFLICT_IDENTIFIED', $evaluator->coi_status);
        $this->assertFalse($this->coi()->mayEvaluate($evaluator));
    }

    public function test_a_declared_conflict_must_describe_the_interest(): void
    {
        $this->evaluator('NOT_COMPLETED');
        $evaluatorId = DB::table('procgov_review_evaluators')->where('review_id', $this->reviewId)->value('id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nature of the interest/');

        $this->coi()->declare($this->companyId, (int) $evaluatorId, ['has_conflict' => true]);
    }

    public function test_clearing_a_conflict_lets_the_evaluator_participate(): void
    {
        $this->criterion();
        $this->bidder();
        $evaluatorId = $this->evaluator('NOT_COMPLETED');

        $declaration = $this->coi()->declare($this->companyId, $evaluatorId, [
            'has_conflict' => true,
            'nature_of_interest' => 'Former employer, ten years ago.',
        ]);

        $this->coi()->resolve($this->companyId, (int) $declaration->id, 'CLEARED', 'Immaterial.');

        $evaluator = DB::table('procgov_review_evaluators')->find($evaluatorId);

        $this->assertSame('APPROVED_TO_PARTICIPATE', $evaluator->coi_status);
        $this->assertTrue($this->coi()->mayEvaluate($evaluator));
    }

    public function test_recusal_withdraws_the_evaluators_work_without_deleting_it(): void
    {
        $this->criterion();
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator();

        $evaluationId = DB::table('procgov_evaluations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'evaluator_id' => $evaluatorId,
            'bidder_id' => $bidder,
            'status' => 'SUBMITTED',
            'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $declaration = $this->coi()->declare($this->companyId, $evaluatorId, [
            'has_conflict' => true,
            'nature_of_interest' => 'Shareholding disclosed late.',
        ]);

        $this->coi()->resolve($this->companyId, (int) $declaration->id, 'RECUSED', 'Material interest.');

        // Kept as a row, excluded from scoring — deleting it would erase the fact that they scored.
        $this->assertSame('WITHDRAWN', DB::table('procgov_evaluations')->find($evaluationId)->status);
    }

    // ── §1.4 the criteria freeze ────────────────────────────────────────────────────────────────

    public function test_opening_evaluation_freezes_the_criteria(): void
    {
        $this->criterion();
        $this->bidder();
        $this->evaluator();

        $problems = $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $this->assertSame([], $problems);
        $this->assertNotNull(DB::table('procgov_tender_reviews')->find($this->reviewId)->criteria_frozen_at);
    }

    public function test_frozen_criteria_cannot_be_edited_directly(): void
    {
        $criterionId = $this->criterion();
        $this->bidder();
        $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        // Change the weighting after scoring begins and a different bidder wins with nobody's
        // scores having changed. That is the attack this refusal exists to stop.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/frozen when evaluation opened/');

        app(CriteriaService::class)->update($this->companyId, $criterionId, ['weight' => 10]);
    }

    public function test_a_criteria_change_after_freezing_is_recorded_and_needs_approval(): void
    {
        $criterionId = $this->criterion();
        $this->bidder();
        $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $change = app(CriteriaService::class)->requestChange(
            $this->companyId, $this->reviewId, $criterionId, 'AMENDED',
            ['weight' => 60], 'Specification corrected after a bidder query.',
        );

        $this->assertSame('PENDING', $change->status);
        $this->assertTrue((bool) $change->requires_rescoring);
        // Nothing has changed on the criterion itself until the change is approved and applied.
        $this->assertEqualsWithDelta(100.0, (float) DB::table('procgov_criteria')->find($criterionId)->weight, 0.001);
    }

    public function test_a_cosmetic_change_does_not_force_re_scoring(): void
    {
        $criterionId = $this->criterion();
        $this->bidder();
        $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        // Forcing a full re-score for a typo correction is a reason people stop correcting typos.
        $change = app(CriteriaService::class)->requestChange(
            $this->companyId, $this->reviewId, $criterionId, 'AMENDED',
            ['description' => 'Clarified wording.'], 'Typo in the criterion description.',
        );

        $this->assertFalse((bool) $change->requires_rescoring);
    }

    public function test_evaluation_cannot_open_with_no_bids(): void
    {
        $this->criterion();
        $this->evaluator();

        $problems = $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $this->assertStringContainsString('No bids', implode(' ', $problems));
    }

    public function test_evaluation_cannot_open_with_weights_that_do_not_total_one_hundred(): void
    {
        $this->criterion(60);
        $this->bidder();
        $this->evaluator();

        $problems = $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $this->assertStringContainsString('must total 100%', implode(' ', $problems));
    }

    // ── §1.5 / §1.6 immutability and correction ─────────────────────────────────────────────────

    public function test_a_submitted_evaluation_cannot_be_edited(): void
    {
        $criterionId = $this->criterion();
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $workspace = $this->evaluations()->workspace($this->companyId, $this->reviewId, $evaluatorId, $bidder);
        $evaluationId = (int) $workspace['evaluation']->id;

        $this->evaluations()->saveDraft($this->companyId, $evaluationId, [$criterionId => ['score' => 80]]);
        $this->assertSame([], $this->evaluations()->submit($this->companyId, $evaluationId));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/submitted and is locked/');

        $this->evaluations()->saveDraft($this->companyId, $evaluationId, [$criterionId => ['score' => 95]]);
    }

    public function test_submission_is_refused_while_a_criterion_is_unscored(): void
    {
        $this->criterion(50);
        $second = $this->criterion(50, ['name' => 'Financial', 'criterion_group' => 'FINANCIAL']);
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $workspace = $this->evaluations()->workspace($this->companyId, $this->reviewId, $evaluatorId, $bidder);
        $evaluationId = (int) $workspace['evaluation']->id;

        $this->evaluations()->saveDraft($this->companyId, $evaluationId, [$second => ['score' => 70]]);

        $problems = $this->evaluations()->submit($this->companyId, $evaluationId);

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('Technical', $problems[0]);
    }

    public function test_reopening_preserves_the_original_scores_and_bumps_the_revision(): void
    {
        // §1.6: "the system must retain the original scores even if a correction is authorised".
        $criterionId = $this->criterion();
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $workspace = $this->evaluations()->workspace($this->companyId, $this->reviewId, $evaluatorId, $bidder);
        $evaluationId = (int) $workspace['evaluation']->id;

        $this->evaluations()->saveDraft($this->companyId, $evaluationId, [$criterionId => ['score' => 80]]);
        $this->evaluations()->submit($this->companyId, $evaluationId);

        $this->evaluations()->reopen($this->companyId, $evaluationId, 'Moderation: arithmetic slip on criterion 1.');

        $evaluation = DB::table('procgov_evaluations')->find($evaluationId);
        $this->assertSame('REOPENED', $evaluation->status);
        $this->assertSame(2, (int) $evaluation->revision);
        $this->assertFalse((bool) $evaluation->is_locked);

        $history = DB::table('procgov_evaluation_score_history')
            ->where('evaluation_id', $evaluationId)
            ->get();

        $this->assertCount(1, $history);
        $this->assertEqualsWithDelta(80.0, (float) $history->first()->score, 0.001);
        $this->assertSame(1, (int) $history->first()->revision);

        // Re-scoring lower must not erase what was originally recorded.
        $this->evaluations()->saveDraft($this->companyId, $evaluationId, [$criterionId => ['score' => 55]]);

        $this->assertEqualsWithDelta(
            80.0,
            (float) DB::table('procgov_evaluation_score_history')->where('evaluation_id', $evaluationId)->value('score'),
            0.001,
        );
        $this->assertEqualsWithDelta(
            55.0,
            (float) DB::table('procgov_evaluation_scores')->where('evaluation_id', $evaluationId)->value('score'),
            0.001,
        );
    }

    public function test_reopening_requires_a_reason(): void
    {
        $criterionId = $this->criterion();
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $workspace = $this->evaluations()->workspace($this->companyId, $this->reviewId, $evaluatorId, $bidder);
        $evaluationId = (int) $workspace['evaluation']->id;

        $this->evaluations()->saveDraft($this->companyId, $evaluationId, [$criterionId => ['score' => 80]]);
        $this->evaluations()->submit($this->companyId, $evaluationId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a reason/');

        $this->evaluations()->reopen($this->companyId, $evaluationId, '   ');
    }

    public function test_a_score_above_the_maximum_is_clamped_rather_than_losing_the_whole_save(): void
    {
        // Rejecting the save would discard the other nineteen criteria the evaluator just did.
        $criterionId = $this->criterion();
        $bidder = $this->bidder();
        $evaluatorId = $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $workspace = $this->evaluations()->workspace($this->companyId, $this->reviewId, $evaluatorId, $bidder);
        $evaluationId = (int) $workspace['evaluation']->id;

        $this->evaluations()->saveDraft($this->companyId, $evaluationId, [$criterionId => ['score' => 150]]);

        $this->assertEqualsWithDelta(
            100.0,
            (float) DB::table('procgov_evaluation_scores')->where('evaluation_id', $evaluationId)->value('score'),
            0.001,
        );
    }

    public function test_bidders_cannot_be_added_once_evaluation_has_opened(): void
    {
        // A bid added mid-evaluation would not have been assessed by every evaluator.
        $this->criterion();
        $this->bidder();
        $this->evaluator();
        $this->reviews()->openEvaluation($this->companyId, $this->reviewId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/bidder list cannot be changed/');

        $this->reviews()->addBidder($this->companyId, $this->reviewId, ['supplier_name' => 'Latecomer']);
    }

    public function test_a_disqualification_must_record_its_reason(): void
    {
        $bidder = $this->bidder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must record its reason/');

        $this->reviews()->disqualify($this->companyId, $bidder, 'ADMINISTRATIVE', '  ');
    }
}
