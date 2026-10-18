<?php

namespace Tests\Unit\HR\Leave;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Models\PolicyLeaveRule;
use App\Modules\HR\Leave\Models\PolicyRuleAuditLog;
use App\Modules\HR\Leave\Services\PolicyRuleEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PolicyRuleEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private PolicyRuleEvaluator $evaluator;
    private HRPerson $employee;
    private LeaveType $leaveType;
    private SubmitLeaveRequestDTO $dto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new PolicyRuleEvaluator();

        /*
         * Real rows rather than partial mocks.
         *
         * The evaluator writes to policy_rule_audit_log and policy_leave_rules, both of which
         * carry foreign keys to hr_people and leave_types — so an employee and a leave type that
         * exist only as mocks fail the constraint the moment a rule is evaluated. `id` is guarded,
         * hence forceFill.
         */
        $this->employee = tap((new HRPerson())->forceFill([
            'id' => 1, 'first_name' => 'Test', 'surname' => 'User',
            'date_joined' => Carbon::now()->subYear()->timestamp,
        ]), fn (HRPerson $p) => $p->save());

        $this->leaveType = tap((new LeaveType())->forceFill([
            'id' => 1, 'name' => 'Annual Leave', 'statutory_type' => 'annual',
        ]), fn (LeaveType $t) => $t->save());

        $this->dto = SubmitLeaveRequestDTO::from([
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => Carbon::today()->addDays(14)->format('Y-m-d'),
            'end_date' => Carbon::today()->addDays(16)->format('Y-m-d'),
            'request_mode' => 'daily',
            'notes' => null,
            'is_overtime' => false,
            'is_bulk' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── No rules → valid ────────────────────────────────────────────────────

    public function test_returns_valid_when_no_rules_exist(): void
    {
        $result = $this->evaluator->evaluate($this->employee, $this->leaveType, $this->dto);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['warnings']);
    }

    // ── Notice period ───────────────────────────────────────────────────────

    public function test_notice_period_passes_when_sufficient_notice_given(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 7], 'fail');

        // dto already has 14 days notice
        $result = $this->evaluator->evaluate($this->employee, $this->leaveType, $this->dto);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_notice_period_fails_when_insufficient_notice(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 30], 'fail');

        $dto = SubmitLeaveRequestDTO::from([
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->addDay()->format('Y-m-d'),
            'request_mode' => 'daily',
            'notes' => null,
            'is_overtime' => false,
            'is_bulk' => false,
        ]);

        $result = $this->evaluator->evaluate($this->employee, $this->leaveType, $dto);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('advance notice', $result['errors'][0]);
    }

    public function test_notice_period_warning_does_not_block(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 30], 'warning');

        $dto = SubmitLeaveRequestDTO::from([
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->addDay()->format('Y-m-d'),
            'request_mode' => 'daily',
            'notes' => null,
            'is_overtime' => false,
            'is_bulk' => false,
        ]);

        $result = $this->evaluator->evaluate($this->employee, $this->leaveType, $dto);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
    }

    // ── Zero notice period → always passes ──────────────────────────────────

    public function test_zero_minimum_notice_always_passes(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 0], 'fail');

        $dto = SubmitLeaveRequestDTO::from([
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => Carbon::today()->format('Y-m-d'),
            'end_date' => Carbon::today()->format('Y-m-d'),
            'request_mode' => 'daily',
            'notes' => null,
            'is_overtime' => false,
            'is_bulk' => false,
        ]);

        $result = $this->evaluator->evaluate($this->employee, $this->leaveType, $dto);

        $this->assertTrue($result['valid']);
    }

    // ── Audit log ───────────────────────────────────────────────────────────

    public function test_each_evaluated_rule_writes_audit_log(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 0], 'fail');
        $this->seedRule('notice_period', ['minimum_notice_days' => 0], 'fail', 'RULE_B');

        // A real row: the audit log carries a foreign key to leave_requests, so a partial mock
        // fails the constraint the moment a rule is recorded.
        $request = LeaveRequest::factory()->create([
            'employee_id'   => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
        ]);

        $this->evaluator->evaluate($this->employee, $this->leaveType, $this->dto, $request);

        $this->assertDatabaseCount('policy_rule_audit_log', 2);
        $this->assertDatabaseHas('policy_rule_audit_log', [
            'employee_id'      => 1,
            'leave_request_id' => $request->id,
            'event_type'       => 'evaluation',
        ]);
    }

    public function test_audit_log_records_pass_outcome(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 0], 'fail');

        $this->evaluator->evaluate($this->employee, $this->leaveType, $this->dto);

        $this->assertDatabaseHas('policy_rule_audit_log', [
            'evaluation_result' => 'pass',
        ]);
    }

    public function test_audit_log_records_fail_outcome(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 30], 'fail');

        $dto = SubmitLeaveRequestDTO::from([
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'request_mode' => 'daily',
            'notes' => null,
            'is_overtime' => false,
            'is_bulk' => false,
        ]);

        $this->evaluator->evaluate($this->employee, $this->leaveType, $dto);

        $this->assertDatabaseHas('policy_rule_audit_log', [
            'evaluation_result' => 'fail',
        ]);
    }

    // ── Scope: rule applies to specific leave type ──────────────────────────

    public function test_rule_scoped_to_different_leave_type_is_skipped(): void
    {
        // A leave type that exists but is not the one being requested — 999 was a foreign key to
        // nothing, so the row could never be written in the first place.
        $otherType = tap((new LeaveType())->forceFill([
            'id' => 2, 'name' => 'Sick Leave', 'statutory_type' => 'sick',
        ]), fn (LeaveType $t) => $t->save());

        PolicyLeaveRule::create([
            'leave_type_id'            => $otherType->id,
            'rule_code'                => 'SCOPED',
            'rule_name'                => 'Scoped Rule',
            'rule_type'                => 'notice_period',
            'rule_config'              => ['minimum_notice_days' => 999],
            'priority'                 => 1,
            'active'                   => true,
            'overrides_bcea'           => false,
            'requires_policy_override' => false,
        ]);

        $result = $this->evaluator->evaluate($this->employee, $this->leaveType, $this->dto);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    // ── Unknown rule type → pass ────────────────────────────────────────────

    /**
     * A rule type the evaluator does not know cannot reach it in the first place.
     *
     * This used to seed `unknown_rule_type` and assert the evaluator waved it through. The column
     * carries a CHECK constraint listing the rule types the system understands, so such a row
     * cannot be written — the defensive branch is unreachable from the database, which is a
     * stronger guarantee than the one the test was asserting. What is worth holding onto is that
     * the constraint is actually there.
     */
    public function test_the_database_refuses_a_rule_type_the_evaluator_does_not_know(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->seedRule('unknown_rule_type', [], 'fail');
    }

    // ── Multiple rules: first blocks, second warns ──────────────────────────

    public function test_multiple_rules_can_have_different_outcomes(): void
    {
        $this->seedRule('notice_period', ['minimum_notice_days' => 30], 'fail',    'BLOCK_RULE');
        $this->seedRule('notice_period', ['minimum_notice_days' => 30], 'warning', 'WARN_RULE');

        $dto = SubmitLeaveRequestDTO::from([
            'employee_id' => 1,
            'leave_type_id' => 1,
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'request_mode' => 'daily',
            'notes' => null,
            'is_overtime' => false,
            'is_bulk' => false,
        ]);

        $result = $this->evaluator->evaluate($this->employee, $this->leaveType, $dto);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertNotEmpty($result['warnings']);
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function seedRule(
        string  $type,
        array   $config,
        string  $breachOutcome,
        string  $code = 'TEST_RULE',
    ): PolicyLeaveRule {
        return PolicyLeaveRule::create([
            'leave_type_id'            => null,
            'rule_code'                => $code,
            'rule_name'                => "Test Rule {$code}",
            'rule_type'                => $type,
            'rule_config'              => array_merge($config, ['breach_outcome' => $breachOutcome]),
            'priority'                 => 1,
            'active'                   => true,
            'overrides_bcea'           => false,
            'requires_policy_override' => false,
        ]);
    }
}
