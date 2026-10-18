<?php

namespace Tests\Unit\ProcurementGovernance;

use App\Modules\Procurement\Governance\Support\Rules\Handlers\CustomExpressionHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\FinancialThresholdHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\MandatoryJustificationHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\RequiredFieldHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\TimeLimitHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The rule handlers that need no database — the ones a tenant configures most and gets wrong most.
 *
 * The distinction these tests protect is SKIPPED versus PASS. A rule that could not be evaluated
 * must never report as satisfied, because a compliance dashboard showing green for a check that
 * never ran is worse than one showing nothing at all.
 */
class RuleHandlersTest extends TestCase
{
    /**
     * `??` would swallow an explicit null and substitute the default, so a test for "no value
     * captured" would silently test 750 000 instead.
     */
    private function subject(array $fields = [], array $overrides = []): RuleSubject
    {
        $pick = fn (string $key, mixed $default) => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $default;

        return new RuleSubject(
            companyId: 1,
            type: 'procgov_deviations',
            id: 1,
            reference: 'DEV-2026-0001',
            fields: $fields,
            amount: $pick('amount', 750_000.0),
            category: 'ICT',
            method: $pick('method', 'SOLE_SOURCE'),
            riskLevel: 'HIGH',
            meta: $pick('meta', []),
        );
    }

    // ── REQUIRED_FIELD ──────────────────────────────────────────────────────────────────────────

    public function test_required_field_passes_when_every_field_has_a_value(): void
    {
        $outcome = (new RequiredFieldHandler)->evaluate(
            $this->subject(['reason' => 'Plant failure', 'risk_assessment' => 'Assessed']),
            ['fields' => ['reason', 'risk_assessment']],
        );

        $this->assertSame(RuleOutcome::PASS, $outcome->outcome);
    }

    public function test_required_field_treats_whitespace_as_blank(): void
    {
        // A field containing a space satisfies "not null" and satisfies nobody else.
        $outcome = (new RequiredFieldHandler)->evaluate(
            $this->subject(['reason' => '   ']),
            ['fields' => ['reason']],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
        $this->assertStringContainsString('Reason', $outcome->message);
    }

    public function test_required_field_names_every_missing_field_at_once(): void
    {
        // Reporting one at a time turns a single fix into four round trips.
        $outcome = (new RequiredFieldHandler)->evaluate(
            $this->subject([]),
            ['fields' => ['reason', 'circumstances']],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
        $this->assertStringContainsString('Reason', $outcome->message);
        $this->assertStringContainsString('Circumstances', $outcome->message);
    }

    public function test_required_field_skips_rather_than_passes_when_no_fields_are_configured(): void
    {
        $outcome = (new RequiredFieldHandler)->evaluate($this->subject(), []);

        $this->assertSame(RuleOutcome::SKIPPED, $outcome->outcome);
    }

    // ── MANDATORY_JUSTIFICATION ─────────────────────────────────────────────────────────────────

    public function test_a_placeholder_justification_is_rejected(): void
    {
        // This is the real failure mode: the field is not empty, it says "n/a".
        $outcome = (new MandatoryJustificationHandler)->evaluate(
            $this->subject(['business_justification' => 'N/A']),
            ['fields' => ['business_justification'], 'min_length' => 50],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
        $this->assertStringContainsString('placeholder', $outcome->message);
    }

    public function test_a_too_short_justification_is_rejected_with_its_length(): void
    {
        $outcome = (new MandatoryJustificationHandler)->evaluate(
            $this->subject(['business_justification' => 'Urgent.']),
            ['fields' => ['business_justification'], 'min_length' => 100],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
        $this->assertStringContainsString('7 characters', $outcome->message);
    }

    public function test_a_substantial_justification_passes(): void
    {
        $outcome = (new MandatoryJustificationHandler)->evaluate(
            $this->subject(['business_justification' => str_repeat('A considered explanation. ', 6)]),
            ['fields' => ['business_justification'], 'min_length' => 100],
        );

        $this->assertSame(RuleOutcome::PASS, $outcome->outcome);
    }

    // ── FINANCIAL_THRESHOLD ─────────────────────────────────────────────────────────────────────

    public function test_a_value_band_can_require_a_procurement_method(): void
    {
        $outcome = (new FinancialThresholdHandler)->evaluate(
            $this->subject([], ['method' => 'SOLE_SOURCE']),
            ['required_methods' => ['OPEN_TENDER']],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
        $this->assertStringContainsString('OPEN_TENDER', $outcome->message);
    }

    public function test_the_required_method_passes_regardless_of_case(): void
    {
        $outcome = (new FinancialThresholdHandler)->evaluate(
            $this->subject([], ['method' => 'open_tender']),
            ['required_methods' => ['OPEN_TENDER']],
        );

        $this->assertSame(RuleOutcome::PASS, $outcome->outcome);
    }

    public function test_a_missing_quote_count_fails_rather_than_passes_silently(): void
    {
        // "We required three quotes and recorded none" must not read as compliant.
        $outcome = (new FinancialThresholdHandler)->evaluate(
            $this->subject([], ['meta' => []]),
            ['min_quotes' => 3],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
    }

    public function test_enough_quotes_passes(): void
    {
        $outcome = (new FinancialThresholdHandler)->evaluate(
            $this->subject([], ['meta' => ['quote_count' => 3]]),
            ['min_quotes' => 3],
        );

        $this->assertSame(RuleOutcome::PASS, $outcome->outcome);
    }

    public function test_threshold_skips_when_there_is_no_value_to_test(): void
    {
        $outcome = (new FinancialThresholdHandler)->evaluate(
            $this->subject([], ['amount' => null]),
            ['required_methods' => ['OPEN_TENDER']],
        );

        $this->assertSame(RuleOutcome::SKIPPED, $outcome->outcome);
    }

    // ── TIME_LIMIT ──────────────────────────────────────────────────────────────────────────────

    public function test_an_interval_within_the_limit_passes(): void
    {
        $outcome = (new TimeLimitHandler)->evaluate(
            $this->subject([
                'tender_closing_at' => Carbon::now()->subDays(5)->toDateString(),
                'evaluation_closed_at' => Carbon::now()->toDateString(),
            ]),
            ['from_field' => 'tender_closing_at', 'to_field' => 'evaluation_closed_at', 'max_days' => 14],
        );

        $this->assertSame(RuleOutcome::PASS, $outcome->outcome);
    }

    public function test_an_overrun_reports_both_the_actual_and_the_limit(): void
    {
        $outcome = (new TimeLimitHandler)->evaluate(
            $this->subject([
                'tender_closing_at' => Carbon::now()->subDays(30)->toDateString(),
            ]),
            ['from_field' => 'tender_closing_at', 'to_field' => 'now', 'max_days' => 14],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
        $this->assertStringContainsString('30 days', $outcome->message);
        $this->assertStringContainsString('14', $outcome->message);
    }

    public function test_a_minimum_advertising_period_is_enforced(): void
    {
        // A tender open for three days when the framework requires 21 is invisible to any rule that
        // only looks for lateness — which is why min_days exists.
        $outcome = (new TimeLimitHandler)->evaluate(
            $this->subject([
                'advertised_at' => Carbon::now()->subDays(3)->toDateString(),
                'tender_closing_at' => Carbon::now()->toDateString(),
            ]),
            ['from_field' => 'advertised_at', 'to_field' => 'tender_closing_at', 'min_days' => 21],
        );

        $this->assertSame(RuleOutcome::FAIL, $outcome->outcome);
        $this->assertStringContainsString('21', $outcome->message);
    }

    public function test_a_missing_date_skips_rather_than_fails(): void
    {
        $outcome = (new TimeLimitHandler)->evaluate(
            $this->subject([]),
            ['from_field' => 'tender_closing_at', 'to_field' => 'now', 'max_days' => 14],
        );

        $this->assertSame(RuleOutcome::SKIPPED, $outcome->outcome);
    }

    // ── CUSTOM ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_simple_comparison_is_evaluated(): void
    {
        $handler = new CustomExpressionHandler;

        $this->assertSame(RuleOutcome::PASS, $handler->evaluate(
            $this->subject(),
            ['expression' => ['field' => 'risk_level', 'operator' => 'in', 'value' => ['HIGH', 'CRITICAL']]],
        )->outcome);

        $this->assertSame(RuleOutcome::FAIL, $handler->evaluate(
            $this->subject(),
            ['expression' => ['field' => 'risk_level', 'operator' => '=', 'value' => 'LOW']],
        )->outcome);
    }

    public function test_all_and_any_groups_combine_branches(): void
    {
        $handler = new CustomExpressionHandler;

        $this->assertSame(RuleOutcome::PASS, $handler->evaluate($this->subject(), [
            'expression' => ['all' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => 500_000],
                ['field' => 'category', 'operator' => '=', 'value' => 'ICT'],
            ]],
        ])->outcome);

        $this->assertSame(RuleOutcome::FAIL, $handler->evaluate($this->subject(), [
            'expression' => ['all' => [
                ['field' => 'amount', 'operator' => '>=', 'value' => 500_000],
                ['field' => 'category', 'operator' => '=', 'value' => 'Construction'],
            ]],
        ])->outcome);

        $this->assertSame(RuleOutcome::PASS, $handler->evaluate($this->subject(), [
            'expression' => ['any' => [
                ['field' => 'category', 'operator' => '=', 'value' => 'Construction'],
                ['field' => 'category', 'operator' => '=', 'value' => 'ICT'],
            ]],
        ])->outcome);
    }

    public function test_a_deeply_nested_expression_is_refused_rather_than_recursed(): void
    {
        // A hand-edited or imported expression must not be able to recurse the evaluator to death.
        $node = ['field' => 'amount', 'operator' => '>', 'value' => 0];
        for ($i = 0; $i < 30; $i++) {
            $node = ['not' => $node];
        }

        $outcome = (new CustomExpressionHandler)->evaluate($this->subject(), ['expression' => $node]);

        $this->assertSame(RuleOutcome::SKIPPED, $outcome->outcome);
        $this->assertStringContainsString('could not be evaluated', $outcome->message);
    }

    public function test_an_unknown_operator_skips_instead_of_passing(): void
    {
        $outcome = (new CustomExpressionHandler)->evaluate($this->subject(), [
            'expression' => ['field' => 'amount', 'operator' => 'system', 'value' => 'rm -rf /'],
        ]);

        $this->assertSame(RuleOutcome::SKIPPED, $outcome->outcome);
    }

    public function test_an_empty_expression_skips(): void
    {
        $this->assertSame(
            RuleOutcome::SKIPPED,
            (new CustomExpressionHandler)->evaluate($this->subject(), [])->outcome,
        );
    }
}
