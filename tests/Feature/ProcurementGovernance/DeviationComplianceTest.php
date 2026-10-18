<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Services\DeviationService;
use App\Modules\Procurement\Governance\Support\Rules\ConditionMatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §5.4: nothing is submitted without a compliance check, a FAIL blocks, and a WARNING may be
 * accepted only knowingly and by name.
 *
 * The last of those is the one that matters at audit. "Who decided this was acceptable" must always
 * have an answer, and it must be attached to the individual rule outcomes rather than to a free-text
 * note somebody may or may not have written.
 */
class DeviationComplianceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $ruleSetId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'requester-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->userId));

        // Start from a known state: only the rule set this test declares is in force.
        DB::table('procgov_rule_sets')->where('company_id', $this->companyId)->update(['is_active' => false]);

        $this->ruleSetId = DB::table('procgov_rule_sets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'code' => 'TEST-'.Str::random(4),
            'name' => 'Test framework',
            'applies_to' => 'deviation',
            'version' => 1,
            'is_active' => true,
            'is_frozen' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A chain must exist or submission is refused for a different (also correct) reason.
        DB::table('procgov_approval_chains')
            ->where('company_id', $this->companyId)->update(['is_active' => false]);

        $chainId = DB::table('procgov_approval_chains')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'code' => 'test-chain-'.Str::random(4),
            'name' => 'Test chain',
            'applies_to' => 'deviation',
            'specificity' => 0,
            'is_active' => true,
            'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('procgov_approval_chain_steps')->insert([
            'company_id' => $this->companyId,
            'chain_id' => $chainId,
            'step_no' => 1,
            'name' => 'Management approval',
            'approver_type' => 'permission',
            'approver_ref' => 'procgov.deviation.approve',
            'quorum' => 1,
            'is_mandatory' => true,
            'can_delegate' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): DeviationService
    {
        return app(DeviationService::class);
    }

    private function rule(string $type, string $severity, array $parameters, ?array $conditions = null): void
    {
        DB::table('procgov_rules')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'rule_set_id' => $this->ruleSetId,
            'code' => strtolower($type).'-'.Str::random(4),
            'name' => Str::headline($type),
            'rule_type' => $type,
            'severity' => $severity,
            'conditions' => $conditions ? json_encode($conditions) : null,
            'parameters' => json_encode($parameters),
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deviation(array $overrides = []): object
    {
        return $this->service()->create($this->companyId, array_merge([
            'title' => 'Emergency pump replacement',
            'procurement_value' => 750_000,
            'reason' => 'The main pump failed overnight.',
            'business_justification' => str_repeat('A considered explanation of the emergency. ', 5),
            'risk_level' => 'HIGH',
        ], $overrides));
    }

    // ── Blocking on FAIL ────────────────────────────────────────────────────────────────────────

    public function test_a_failing_check_blocks_submission_and_names_the_findings(): void
    {
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['risk_assessment', 'circumstances']]);

        $deviation = $this->deviation();

        $result = $this->service()->submit($this->companyId, (int) $deviation->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('FAIL', $result['check']->result);
        $this->assertNotEmpty($result['problems']);

        // It must not have moved out of draft, or the queue fills with unsubmittable items.
        $this->assertSame('DRAFT', DB::table('procgov_deviations')->find($deviation->id)->status);
    }

    public function test_the_failed_check_is_recorded_against_the_deviation(): void
    {
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['risk_assessment']]);

        $deviation = $this->deviation();
        $this->service()->submit($this->companyId, (int) $deviation->id);

        $row = DB::table('procgov_deviations')->find($deviation->id);

        $this->assertSame('FAIL', $row->compliance_result);
        $this->assertNotNull($row->compliance_check_id);
    }

    public function test_a_satisfied_rule_lets_the_deviation_through(): void
    {
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['reason', 'business_justification']]);

        $deviation = $this->deviation();

        $result = $this->service()->submit($this->companyId, (int) $deviation->id);

        $this->assertTrue($result['ok'], implode(' ', $result['problems']));
        $this->assertSame('PASS', $result['check']->result);
        $this->assertSame('SUBMITTED', DB::table('procgov_deviations')->find($deviation->id)->status);
    }

    // ── Warnings ────────────────────────────────────────────────────────────────────────────────

    public function test_a_warning_stops_submission_until_it_is_knowingly_accepted(): void
    {
        $this->rule('REQUIRED_FIELD', 'WARNING', ['fields' => ['proposed_alternative']]);

        $deviation = $this->deviation();

        $blocked = $this->service()->submit($this->companyId, (int) $deviation->id);

        $this->assertFalse($blocked['ok']);
        $this->assertSame('WARNING', $blocked['check']->result);

        $accepted = $this->service()->submit(
            $this->companyId,
            (int) $deviation->id,
            acceptWarnings: true,
            acceptanceReason: 'No alternative exists; the plant is down.',
        );

        $this->assertTrue($accepted['ok'], implode(' ', $accepted['problems']));
    }

    public function test_accepting_a_warning_records_who_accepted_it_and_why(): void
    {
        $this->rule('REQUIRED_FIELD', 'WARNING', ['fields' => ['proposed_alternative']]);

        $deviation = $this->deviation();

        $result = $this->service()->submit(
            $this->companyId,
            (int) $deviation->id,
            acceptWarnings: true,
            acceptanceReason: 'No alternative exists; the plant is down.',
        );

        $acknowledged = DB::table('procgov_compliance_results')
            ->where('check_id', $result['check']->id)
            ->where('outcome', 'WARNING')
            ->first();

        $this->assertSame($this->userId, (int) $acknowledged->acknowledged_by);
        $this->assertSame('No alternative exists; the plant is down.', $acknowledged->acknowledgement_reason);
        $this->assertNotNull($acknowledged->acknowledged_at);
    }

    // ── Rule selection ──────────────────────────────────────────────────────────────────────────

    public function test_a_rule_outside_the_value_band_does_not_apply(): void
    {
        // A rule that fires on every value regardless of its band would make thresholds meaningless.
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['risk_assessment']], ['min_value' => 5_000_000]);

        $result = $this->service()->submit($this->companyId, (int) $this->deviation()->id);

        $this->assertTrue($result['ok'], implode(' ', $result['problems']));
        $this->assertSame(0, (int) $result['check']->rules_evaluated);
    }

    public function test_a_rule_inside_the_value_band_applies(): void
    {
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['risk_assessment']], ['min_value' => 500_000]);

        $result = $this->service()->submit($this->companyId, (int) $this->deviation()->id);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, (int) $result['check']->rules_evaluated);
    }

    public function test_the_check_records_which_rule_set_version_produced_it(): void
    {
        // "This deviation passed compliance" is only meaningful alongside the rules in force when
        // it did — an auditor two years later is asking exactly that.
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['reason']]);

        $result = $this->service()->submit($this->companyId, (int) $this->deviation()->id);

        $this->assertSame(1, (int) $result['check']->rule_set_version);
        $this->assertNotNull($result['check']->rule_set_code);
    }

    // ── Preflight ───────────────────────────────────────────────────────────────────────────────

    public function test_preflight_reports_the_same_verdict_without_persisting(): void
    {
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['risk_assessment']]);

        $deviation = $this->deviation();
        $before = DB::table('procgov_compliance_checks')->where('company_id', $this->companyId)->count();

        $preview = $this->service()->preflight($this->companyId, (int) $deviation->id);

        $this->assertSame('FAIL', $preview['result']);
        $this->assertNotEmpty($preview['failures']);
        $this->assertSame(
            $before,
            DB::table('procgov_compliance_checks')->where('company_id', $this->companyId)->count(),
        );
    }

    public function test_a_skipped_rule_is_reported_separately_from_a_pass(): void
    {
        // SUPPLIER_COMPLIANCE with no supplier linked cannot be evaluated. It must show as skipped,
        // never fold into the pass count.
        $this->rule('SUPPLIER_COMPLIANCE', 'FAIL', ['require_active' => true]);

        $preview = $this->service()->preflight($this->companyId, (int) $this->deviation()->id);

        $this->assertSame('PASS', $preview['result']);
        $this->assertNotEmpty($preview['skipped']);
        $this->assertEmpty($preview['failures']);
    }

    // ── Workflow ────────────────────────────────────────────────────────────────────────────────

    public function test_a_submitted_deviation_cannot_be_edited(): void
    {
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['reason']]);

        $deviation = $this->deviation();
        $this->service()->submit($this->companyId, (int) $deviation->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/under review cannot be edited/');

        $this->service()->update($this->companyId, (int) $deviation->id, ['procurement_value' => 1]);
    }

    public function test_an_invalid_workflow_transition_is_refused(): void
    {
        $deviation = $this->deviation();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot move to/');

        $this->service()->transition($this->companyId, (int) $deviation->id, 'CLOSED');
    }

    public function test_a_decision_is_recorded_with_its_comment(): void
    {
        $deviation = $this->deviation();

        $decided = $this->service()->decide(
            $this->companyId, (int) $deviation->id, 'APPROVED', 'Emergency accepted.',
        );

        $this->assertSame('APPROVED', $decided->status);
        $this->assertSame('Emergency accepted.', $decided->decision_comment);
        $this->assertNotNull($decided->decided_at);
    }

    public function test_every_transition_is_written_to_the_deviation_history(): void
    {
        $this->rule('REQUIRED_FIELD', 'FAIL', ['fields' => ['reason']]);

        $deviation = $this->deviation();
        $this->service()->submit($this->companyId, (int) $deviation->id);

        $history = $this->service()->history($this->companyId, (int) $deviation->id);

        $this->assertGreaterThanOrEqual(2, $history->count());
        $this->assertSame('DRAFT', $history->first()->to_status);
        $this->assertSame('SUBMITTED', $history->last()->to_status);
    }

    public function test_the_selected_chain_matches_the_deviations_own_attributes(): void
    {
        // The requester must not be able to influence which chain applies; it is selected from the
        // deviation's value, department, type and risk.
        $matcher = app(ConditionMatcher::class);

        $this->assertTrue($matcher->matches(
            ['min_value' => 500_000, 'risk_levels' => ['HIGH']],
            $this->service()->asRuleSubject($this->companyId, (object) [
                'id' => 1, 'reference' => 'DEV', 'procurement_value' => 750_000,
                'company_unit_id' => null, 'deviation_type_code' => 'emergency',
                'risk_level' => 'HIGH', 'status' => 'DRAFT', 'record_id' => null, 'supplier_id' => null,
            ]),
        ));
    }
}
