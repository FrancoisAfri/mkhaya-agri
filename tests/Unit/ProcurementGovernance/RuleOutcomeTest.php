<?php

namespace Tests\Unit\ProcurementGovernance;

use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Severity is the rule's, not the handler's — the same missing document blocks submission at one
 * organisation and merely annotates it at another, and that is exactly the configurability §5.3
 * asks for. These tests pin the one direction that must never invert: a skipped check never
 * becomes a pass.
 */
class RuleOutcomeTest extends TestCase
{
    public function test_a_failure_becomes_a_warning_when_the_rule_says_so(): void
    {
        $this->assertSame(
            RuleOutcome::WARNING,
            RuleOutcome::fail('Missing certificate')->applySeverity('WARNING')->outcome,
        );
    }

    public function test_an_info_severity_also_downgrades_to_a_warning(): void
    {
        // INFO is advisory; it must still be visible, so it lands on WARNING rather than PASS.
        $this->assertSame(
            RuleOutcome::WARNING,
            RuleOutcome::fail('Advisory')->applySeverity('INFO')->outcome,
        );
    }

    public function test_a_failure_stays_a_failure_at_fail_severity(): void
    {
        $this->assertSame(
            RuleOutcome::FAIL,
            RuleOutcome::fail('Missing certificate')->applySeverity('FAIL')->outcome,
        );
    }

    public function test_severity_never_turns_a_skip_into_a_pass(): void
    {
        // The whole point: "we could not check" must not be reported as "we checked and it is fine".
        foreach (['FAIL', 'WARNING', 'INFO'] as $severity) {
            $this->assertSame(
                RuleOutcome::SKIPPED,
                RuleOutcome::skip('Budget module absent')->applySeverity($severity)->outcome,
            );
        }
    }

    public function test_severity_never_turns_a_pass_into_a_failure(): void
    {
        $this->assertSame(
            RuleOutcome::PASS,
            RuleOutcome::pass()->applySeverity('FAIL')->outcome,
        );
    }

    public function test_a_tenants_own_wording_replaces_the_handlers(): void
    {
        $outcome = RuleOutcome::fail('Generic message')->withMessage('Attach the SBD4 form.');

        $this->assertSame('Attach the SBD4 form.', $outcome->message);
    }

    public function test_an_empty_override_leaves_the_original_message(): void
    {
        $outcome = RuleOutcome::fail('Generic message')->withMessage(null);

        $this->assertSame('Generic message', $outcome->message);
    }

    public function test_detail_survives_severity_application(): void
    {
        // The detail is what tells the user *which* document was missing; losing it on downgrade
        // would make every warning unactionable.
        $outcome = RuleOutcome::fail('Missing', ['missing' => ['TAX_CLEARANCE']])
            ->applySeverity('WARNING');

        $this->assertSame(['missing' => ['TAX_CLEARANCE']], $outcome->detail);
    }
}
