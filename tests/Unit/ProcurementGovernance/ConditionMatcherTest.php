<?php

namespace Tests\Unit\ProcurementGovernance;

use App\Modules\Procurement\Governance\Support\Rules\ConditionMatcher;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use PHPUnit\Framework\TestCase;

/**
 * The matcher decides which compliance rules apply and which approval chain routes a request.
 *
 * Both are consequential: a mis-matched band sends a three-million-rand deviation to a department
 * manager, or lets it skip a rule entirely. These tests exist because that failure is silent —
 * nothing errors, the wrong person simply approves it.
 */
class ConditionMatcherTest extends TestCase
{
    private ConditionMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ConditionMatcher;
    }

    /**
     * `??` would swallow an explicit null and hand back the default, so a test for "no amount
     * captured" would silently test 100 000 instead — array_key_exists is the only way to let a
     * caller mean null.
     */
    private function subject(array $overrides = []): RuleSubject
    {
        $pick = fn (string $key, mixed $default) => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $default;

        return new RuleSubject(
            companyId: 1,
            type: 'procgov_deviations',
            id: 1,
            reference: 'DEV-2026-0001',
            fields: $pick('fields', []),
            amount: $pick('amount', 100_000.0),
            category: $pick('category', 'ICT'),
            companyUnitId: $pick('companyUnitId', 12),
            method: $pick('method', 'SOLE_SOURCE'),
            subtype: $pick('subtype', 'emergency'),
            riskLevel: $pick('riskLevel', 'HIGH'),
            status: $pick('status', 'DRAFT'),
        );
    }

    // ── Value bands ─────────────────────────────────────────────────────────────────────────────

    public function test_no_conditions_always_match(): void
    {
        $this->assertTrue($this->matcher->matches(null, $this->subject()));
        $this->assertTrue($this->matcher->matches([], $this->subject()));
    }

    public function test_adjacent_bands_tile_without_overlap_or_gap(): void
    {
        // 0–500k and 500k–1m. Exactly 500 000 must fall in the *second* band and only the second,
        // or two chains match one request and the route becomes non-deterministic.
        $lower = ['min_value' => 0, 'max_value' => 500_000];
        $upper = ['min_value' => 500_000, 'max_value' => 1_000_000];

        $atBoundary = $this->subject(['amount' => 500_000.0]);

        $this->assertFalse($this->matcher->matches($lower, $atBoundary));
        $this->assertTrue($this->matcher->matches($upper, $atBoundary));

        $justBelow = $this->subject(['amount' => 499_999.99]);

        $this->assertTrue($this->matcher->matches($lower, $justBelow));
        $this->assertFalse($this->matcher->matches($upper, $justBelow));
    }

    public function test_a_missing_amount_never_satisfies_a_value_band(): void
    {
        // "Above R1m" must not fire on a subject whose value nobody has captured. The missing value
        // is a REQUIRED_FIELD problem, and reporting it as a threshold breach hides the real one.
        $subject = $this->subject(['amount' => null]);

        $this->assertFalse($this->matcher->matches(['min_value' => 1_000_000], $subject));
        $this->assertFalse($this->matcher->matches(['max_value' => 1_000_000], $subject));
        $this->assertTrue($this->matcher->matches(['categories' => ['ICT']], $subject));
    }

    public function test_an_open_ended_band_matches_everything_above_it(): void
    {
        $this->assertTrue($this->matcher->matches(
            ['min_value' => 50_000],
            $this->subject(['amount' => 50_000_000.0]),
        ));
    }

    // ── List clauses ────────────────────────────────────────────────────────────────────────────

    public function test_every_present_clause_must_match(): void
    {
        $subject = $this->subject();

        $this->assertTrue($this->matcher->matches([
            'categories' => ['ICT', 'Construction'],
            'risk_levels' => ['HIGH', 'CRITICAL'],
            'methods' => ['SOLE_SOURCE'],
        ], $subject));

        // One clause failing is enough to exclude the rule.
        $this->assertFalse($this->matcher->matches([
            'categories' => ['ICT'],
            'risk_levels' => ['LOW'],
        ], $subject));
    }

    public function test_string_clauses_match_case_insensitively(): void
    {
        // Conditions are typed by hand into a settings screen; "ict" and "ICT" mean the same thing.
        $this->assertTrue($this->matcher->matches(
            ['categories' => ['ict']],
            $this->subject(['category' => 'ICT']),
        ));
    }

    public function test_ids_match_across_the_string_integer_boundary(): void
    {
        // Conditions arrive from JSON as strings; the subject carries an int. A strict comparison
        // here would silently never match, and the rule would appear configured but inert.
        $this->assertTrue($this->matcher->matches(
            ['company_unit_ids' => ['12']],
            $this->subject(['companyUnitId' => 12]),
        ));
    }

    public function test_a_null_subject_value_fails_a_list_clause(): void
    {
        $this->assertFalse($this->matcher->matches(
            ['categories' => ['ICT']],
            $this->subject(['category' => null]),
        ));
    }

    // ── Specificity ─────────────────────────────────────────────────────────────────────────────

    public function test_more_clauses_score_more_specific(): void
    {
        $general = $this->matcher->specificity(['min_value' => 0]);
        $specific = $this->matcher->specificity([
            'min_value' => 0, 'max_value' => 500_000, 'categories' => ['ICT'],
        ]);

        $this->assertGreaterThan($general, $specific);
    }

    public function test_a_narrower_list_scores_more_specific_than_a_wide_one(): void
    {
        $narrow = $this->matcher->specificity(['categories' => ['ICT']]);
        $wide = $this->matcher->specificity(['categories' => ['A', 'B', 'C', 'D', 'E', 'F']]);

        $this->assertGreaterThan($wide, $narrow);
    }

    public function test_empty_conditions_are_the_least_specific(): void
    {
        $this->assertSame(0, $this->matcher->specificity(null));
        $this->assertSame(0, $this->matcher->specificity([]));
    }
}
