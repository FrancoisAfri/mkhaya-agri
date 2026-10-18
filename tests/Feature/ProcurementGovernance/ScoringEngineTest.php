<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\ScoringEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §1.6 automatic scoring — the arithmetic that decides who wins a tender.
 *
 * Three things are pinned here because getting any of them wrong changes an award while looking
 * entirely plausible: nested weights resolve to the share of 100 they actually represent,
 * evaluators are averaged per criterion rather than per total, and a disqualified bid neither
 * ranks nor sets the price benchmark other bids are measured against.
 */
class ScoringEngineTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $reviewId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->reviewId = $this->createReview();
    }

    private function engine(): ScoringEngine
    {
        return app(ScoringEngine::class);
    }

    private function createReview(string $methodology = 'WEIGHTED'): int
    {
        return DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-TEST-'.Str::random(6),
            'title' => 'Network refresh',
            'methodology' => $methodology,
            'status' => 'EVALUATION',
            'currency' => 'ZAR',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function criterion(array $attributes): int
    {
        return DB::table('procgov_criteria')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'criterion_group' => 'TECHNICAL',
            'max_score' => 100,
            'scoring_method' => 'POINTS',
            'is_mandatory' => false,
            'is_pass_fail' => false,
            'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function bidder(string $name, ?float $amount = null, array $attributes = []): int
    {
        return DB::table('procgov_bidders')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'supplier_name' => $name,
            'bid_amount' => $amount,
            'currency' => 'ZAR',
            'is_disqualified' => false,
            'status' => 'EVALUATING',
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function evaluator(): int
    {
        return DB::table('procgov_review_evaluators')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'is_external' => false,
            'external_name' => 'Evaluator '.Str::random(4),
            'member_role' => 'MEMBER',
            'is_voting' => true,
            'coi_status' => 'DECLARED',
            'status' => 'SUBMITTED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<int,float|null>  $scores  criterion id => score */
    private function submitEvaluation(int $evaluatorId, int $bidderId, array $scores): int
    {
        $evaluationId = DB::table('procgov_evaluations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'evaluator_id' => $evaluatorId,
            'bidder_id' => $bidderId,
            'status' => 'SUBMITTED',
            'revision' => 1,
            'is_locked' => true,
            'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($scores as $criterionId => $score) {
            DB::table('procgov_evaluation_scores')->insert([
                'company_id' => $this->companyId,
                'evaluation_id' => $evaluationId,
                'criterion_id' => $criterionId,
                'score' => $score,
                'max_score' => DB::table('procgov_criteria')->where('id', $criterionId)->value('max_score'),
                'weight_applied' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $evaluationId;
    }

    private function bidderRow(int $id): object
    {
        return DB::table('procgov_bidders')->find($id);
    }

    // ── Effective weight ────────────────────────────────────────────────────────────────────────

    public function test_a_sub_criterion_weight_is_a_share_of_its_parent_not_of_the_whole(): void
    {
        // Technical 40%, with a sub-criterion worth 25% of it → 10 points of the overall 100.
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 40]);
        $child = $this->criterion(['name' => 'Methodology', 'weight' => 25, 'parent_id' => $technical]);
        $this->criterion(['name' => 'Team', 'weight' => 75, 'parent_id' => $technical]);
        $financial = $this->criterion(['name' => 'Financial', 'weight' => 60, 'criterion_group' => 'FINANCIAL']);

        $tree = $this->engine()->criteriaTree($this->companyId, $this->reviewId);
        $weights = $tree['all']->pluck('effective_weight', 'id');

        $this->assertEqualsWithDelta(10.0, (float) $weights[$child], 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $weights[$tree['all']->firstWhere('name', 'Team')->id], 0.0001);
        $this->assertEqualsWithDelta(60.0, (float) $weights[$financial], 0.0001);
    }

    public function test_only_leaves_carry_scores_so_a_parent_is_never_double_counted(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);
        $this->criterion(['name' => 'Methodology', 'weight' => 50, 'parent_id' => $technical]);
        $this->criterion(['name' => 'Team', 'weight' => 50, 'parent_id' => $technical]);

        $tree = $this->engine()->criteriaTree($this->companyId, $this->reviewId);

        $this->assertSame(2, $tree['leaves']->count());
        $this->assertNotContains($technical, $tree['leaves']->pluck('id')->all());
    }

    public function test_weights_must_total_one_hundred_at_every_level(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 40]);
        $this->criterion(['name' => 'Financial', 'weight' => 40]);
        $this->criterion(['name' => 'Sub', 'weight' => 90, 'parent_id' => $technical]);

        $problems = $this->engine()->validateWeights($this->companyId, $this->reviewId);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('80', $problems[0]);
        $this->assertStringContainsString('90', $problems[1]);
    }

    public function test_a_sound_tree_reports_no_problems(): void
    {
        $this->criterion(['name' => 'Technical', 'weight' => 60]);
        $this->criterion(['name' => 'Financial', 'weight' => 40, 'criterion_group' => 'FINANCIAL']);

        $this->assertSame([], $this->engine()->validateWeights($this->companyId, $this->reviewId));
    }

    // ── Committee score ─────────────────────────────────────────────────────────────────────────

    public function test_evaluators_are_averaged_per_criterion_then_weighted_once(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);

        $bidder = $this->bidder('Acme', 1_000_000);
        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 80.0]);
        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 60.0]);

        $this->engine()->computeReview($this->companyId, $this->reviewId);

        // (80 + 60) / 2 = 70 of 100, weighted at 100% → 70.
        $this->assertEqualsWithDelta(70.0, (float) $this->bidderRow($bidder)->weighted_score, 0.001);
    }

    public function test_an_incomplete_evaluation_does_not_reweight_the_whole_tender(): void
    {
        // One evaluator scored both criteria; the other skipped the financial one. Averaging totals
        // would drag the committee score down by half the missing criterion's weight; averaging per
        // criterion gives the committee's actual view of each.
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 50]);
        $financial = $this->criterion(['name' => 'Financial', 'weight' => 50, 'criterion_group' => 'FINANCIAL']);

        $bidder = $this->bidder('Acme', 1_000_000);
        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 80.0, $financial => 60.0]);
        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 60.0, $financial => null]);

        $this->engine()->computeReview($this->companyId, $this->reviewId);

        // Technical avg 70 → 35; financial avg 60 (one scorer) → 30; total 65.
        $this->assertEqualsWithDelta(65.0, (float) $this->bidderRow($bidder)->weighted_score, 0.001);
    }

    public function test_only_submitted_evaluations_count_towards_the_committee_score(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);
        $bidder = $this->bidder('Acme', 1_000_000);

        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 80.0]);

        $draft = $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 20.0]);
        DB::table('procgov_evaluations')->where('id', $draft)->update(['status' => 'DRAFT']);

        $this->engine()->computeReview($this->companyId, $this->reviewId);

        // A draft is one evaluator's work in progress; it must not move a ranking others can see.
        $this->assertEqualsWithDelta(80.0, (float) $this->bidderRow($bidder)->weighted_score, 0.001);
    }

    // ── Ranking ─────────────────────────────────────────────────────────────────────────────────

    public function test_bidders_rank_by_weighted_score_and_by_price_separately(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);

        $cheapButWeak = $this->bidder('Cheap', 500_000);
        $dearButStrong = $this->bidder('Strong', 900_000);

        $evaluator = $this->evaluator();
        $this->submitEvaluation($evaluator, $cheapButWeak, [$technical => 40.0]);
        $this->submitEvaluation($evaluator, $dearButStrong, [$technical => 90.0]);

        $this->engine()->computeReview($this->companyId, $this->reviewId);

        $this->assertSame(1, (int) $this->bidderRow($dearButStrong)->overall_rank);
        $this->assertSame(2, (int) $this->bidderRow($cheapButWeak)->overall_rank);

        $this->assertSame(1, (int) $this->bidderRow($cheapButWeak)->financial_rank);
        $this->assertSame(2, (int) $this->bidderRow($dearButStrong)->financial_rank);
    }

    public function test_tied_scores_share_a_rank(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);

        $a = $this->bidder('A', 100_000);
        $b = $this->bidder('B', 200_000);

        $evaluator = $this->evaluator();
        $this->submitEvaluation($evaluator, $a, [$technical => 75.0]);
        $this->submitEvaluation($evaluator, $b, [$technical => 75.0]);

        $this->engine()->computeReview($this->companyId, $this->reviewId);

        $this->assertSame(1, (int) $this->bidderRow($a)->overall_rank);
        $this->assertSame(1, (int) $this->bidderRow($b)->overall_rank);
    }

    public function test_a_disqualified_bid_keeps_its_score_but_does_not_rank(): void
    {
        // §1.8 needs the score in the report alongside the disqualification and its reason.
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);

        $good = $this->bidder('Compliant', 800_000);
        $out = $this->bidder('Excluded', 100_000, [
            'is_disqualified' => true,
            'disqualification_stage' => 'ADMINISTRATIVE',
            'disqualification_reason' => 'No tax clearance certificate.',
        ]);

        $evaluator = $this->evaluator();
        $this->submitEvaluation($evaluator, $good, [$technical => 70.0]);
        $this->submitEvaluation($evaluator, $out, [$technical => 95.0]);

        $this->engine()->computeReview($this->companyId, $this->reviewId);

        $this->assertNull($this->bidderRow($out)->overall_rank);
        $this->assertNull($this->bidderRow($out)->financial_rank);
        $this->assertEqualsWithDelta(95.0, (float) $this->bidderRow($out)->weighted_score, 0.001);
        $this->assertSame(1, (int) $this->bidderRow($good)->overall_rank);
    }

    public function test_a_disqualified_bid_does_not_set_the_price_benchmark(): void
    {
        // A disqualified low bid setting the benchmark would depress every compliant bidder's
        // price points — the classic way a thrown-out bid still decides a tender.
        $this->reviewId = $this->createReview('PRICE_PREFERENCE');
        DB::table('procgov_tender_reviews')->where('id', $this->reviewId)
            ->update(['price_weight' => 80, 'preference_weight' => 20]);

        $this->criterion(['name' => 'Technical', 'weight' => 100]);

        $lowest = $this->bidder('Excluded', 100_000, [
            'is_disqualified' => true,
            'disqualification_reason' => 'Late submission.',
        ]);
        $compliant = $this->bidder('Compliant', 200_000);

        $this->engine()->computeReview($this->companyId, $this->reviewId);

        // Benchmarked against the cheapest *acceptable* bid (itself), so it takes the full 80.
        $this->assertEqualsWithDelta(80.0, (float) $this->bidderRow($compliant)->financial_score, 0.001);
        $this->assertNotNull($this->bidderRow($lowest)->disqualification_reason);
    }

    // ── Thresholds ──────────────────────────────────────────────────────────────────────────────

    public function test_failing_a_minimum_threshold_excludes_a_bid_from_ranking(): void
    {
        $technical = $this->criterion([
            'name' => 'Technical', 'weight' => 100, 'minimum_threshold' => 60,
        ]);

        $passes = $this->bidder('Above', 500_000);
        $fails = $this->bidder('Below', 300_000);

        $evaluator = $this->evaluator();
        $passing = $this->submitEvaluation($evaluator, $passes, [$technical => 70.0]);
        $failing = $this->submitEvaluation($evaluator, $fails, [$technical => 50.0]);

        // computeEvaluation is what sets threshold_passed on each evaluation.
        $this->engine()->computeEvaluation($this->companyId, $passing);
        $this->engine()->computeEvaluation($this->companyId, $failing);
        $this->engine()->computeReview($this->companyId, $this->reviewId);

        $this->assertTrue((bool) $this->bidderRow($passes)->threshold_passed);
        $this->assertFalse((bool) $this->bidderRow($fails)->threshold_passed);
        $this->assertSame(1, (int) $this->bidderRow($passes)->overall_rank);
        $this->assertNull($this->bidderRow($fails)->overall_rank);
    }

    // ── Variance (§1.7 input) ───────────────────────────────────────────────────────────────────

    public function test_material_disagreement_between_evaluators_is_surfaced(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);
        $bidder = $this->bidder('Acme', 1_000_000);

        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 90.0]);
        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 40.0]);

        $variances = $this->engine()->variances($this->companyId, $this->reviewId, 20.0);

        $this->assertCount(1, $variances);
        $this->assertEqualsWithDelta(50.0, $variances[0]['variance'], 0.001);
        $this->assertSame('Acme', $variances[0]['supplier_name']);
    }

    public function test_agreement_within_the_threshold_is_not_flagged(): void
    {
        $technical = $this->criterion(['name' => 'Technical', 'weight' => 100]);
        $bidder = $this->bidder('Acme', 1_000_000);

        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 70.0]);
        $this->submitEvaluation($this->evaluator(), $bidder, [$technical => 60.0]);

        $this->assertSame([], $this->engine()->variances($this->companyId, $this->reviewId, 20.0));
    }
}
