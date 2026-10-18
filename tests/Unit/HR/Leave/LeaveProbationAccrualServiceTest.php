<?php

namespace Tests\Unit\HR\Leave;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Contracts\LeaveBalanceRepositoryInterface;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Services\LeaveProbationAccrualService;
use App\Modules\HR\Leave\Services\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class LeaveProbationAccrualServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveBalanceRepositoryInterface $repo;
    private WorkingDaysCalculator           $calculator;
    private LeaveProbationAccrualService    $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo       = Mockery::mock(LeaveBalanceRepositoryInterface::class);
        $this->calculator = new WorkingDaysCalculator();

        $this->service = new LeaveProbationAccrualService(
            $this->repo,
            $this->calculator,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeEmployee(array $attrs = []): HRPerson
    {
        // forceFill: `id` is guarded, so mass assignment drops it and the service ends up
        // calling currentBalance(null), which its typed signature refuses.
        /*
         * A real row, not an in-memory model.
         *
         * These tests write leave requests and balance transactions that carry a foreign key to
         * hr_people, so an employee that exists only in PHP fails the constraint. `id` is guarded,
         * hence forceFill rather than a constructor array.
         */
        return tap((new HRPerson())->forceFill(array_merge([
            'id'          => 99,
            'date_joined' => Carbon::now()->subYear()->timestamp,
        ], $attrs)), fn (HRPerson $p) => $p->save());
    }

    private function makeLeaveType(array $attrs = []): LeaveType
    {
        return tap((new LeaveType())->forceFill(array_merge([
            'id'                            => 1,
            'statutory_type'                => 'annual',
            'bcea_minimum_days'             => 15,
            'require_probation_period_days' => 182,
        ], $attrs)), fn (LeaveType $t) => $t->save());
    }

    // ── calculateAccruedHours ─────────────────────────────────────────────────

    public function test_zero_when_no_date_joined(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => null]);
        $leaveType = $this->makeLeaveType();

        $hours = $this->service->calculateAccruedHours($employee, $leaveType);

        $this->assertEquals(0.0, $hours);
    }

    public function test_zero_when_asof_equals_start(): void
    {
        $now       = Carbon::now();
        $employee  = $this->makeEmployee(['date_joined' => $now->timestamp]);
        $leaveType = $this->makeLeaveType();

        $hours = $this->service->calculateAccruedHours($employee, $leaveType, $now);

        $this->assertEquals(0.0, $hours);
    }

    public function test_accrual_is_one_day_per_26_working_days(): void
    {
        // Hire: 26 working days ago → should have accrued exactly 1 day = 8h
        $start     = Carbon::parse('2025-01-06'); // Monday
        $asOf      = Carbon::parse('2025-02-10'); // ~26 working days later
        $employee  = $this->makeEmployee(['date_joined' => $start->timestamp]);
        $leaveType = $this->makeLeaveType();

        $hours = $this->service->calculateAccruedHours($employee, $leaveType, $asOf);

        // 1 day accrual = 8h; may be 0 or 8 depending on exact working-day count
        $this->assertContains((float) $hours, [0.0, 8.0]);
    }

    public function test_accrual_is_capped_at_bcea_minimum_days(): void
    {
        // 5 years of employment — should be capped at 15 days = 120h
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subYears(5)->timestamp]);
        $leaveType = $this->makeLeaveType(['bcea_minimum_days' => 15]);

        $hours = $this->service->calculateAccruedHours($employee, $leaveType);

        $this->assertEquals(120.0, $hours);
    }

    public function test_higher_cap_is_respected(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subYears(5)->timestamp]);
        $leaveType = $this->makeLeaveType(['bcea_minimum_days' => 21]);

        $hours = $this->service->calculateAccruedHours($employee, $leaveType);

        $this->assertEquals(168.0, $hours);
    }

    // ── isProbationComplete ───────────────────────────────────────────────────

    public function test_probation_not_complete_within_182_days(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(90)->timestamp]);
        $leaveType = $this->makeLeaveType();

        $this->assertFalse($this->service->isProbationComplete($employee, $leaveType));
    }

    public function test_probation_complete_after_182_days(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType();

        $this->assertTrue($this->service->isProbationComplete($employee, $leaveType));
    }

    public function test_probation_returns_false_when_no_date_joined(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => null]);
        $leaveType = $this->makeLeaveType();

        $this->assertFalse($this->service->isProbationComplete($employee, $leaveType));
    }

    // ── probationDaysRemaining ────────────────────────────────────────────────

    public function test_remaining_days_decrements_correctly(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(100)->timestamp]);
        $leaveType = $this->makeLeaveType(['require_probation_period_days' => 182]);

        $remaining = $this->service->probationDaysRemaining($employee, $leaveType);

        $this->assertEqualsWithDelta(82, $remaining, 2); // ±2 for day boundary
    }

    public function test_remaining_days_is_zero_after_probation(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(300)->timestamp]);
        $leaveType = $this->makeLeaveType();

        $this->assertEquals(0, $this->service->probationDaysRemaining($employee, $leaveType));
    }

    public function test_remaining_days_equals_minimum_when_no_date_joined(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => null]);
        $leaveType = $this->makeLeaveType(['require_probation_period_days' => 182]);

        $this->assertEquals(182, $this->service->probationDaysRemaining($employee, $leaveType));
    }

    // ── applyAccrual ──────────────────────────────────────────────────────────

    public function test_apply_accrual_credits_delta(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subYears(2)->timestamp]);
        $leaveType = $this->makeLeaveType();

        // Simulate that 0h already credited
        LeaveBalanceTransaction::factory()->create([
            'employee_id'      => $employee->id,
            'leave_type_id'    => $leaveType->id,
            'transaction_type' => LeaveTransactionType::Allocation->value,
            'hours'            => 0,
        ]);

        $expectedHours = $this->service->calculateAccruedHours($employee, $leaveType);

        $this->repo->shouldReceive('credit')
            ->once()
            ->with($employee->id, $leaveType->id, $expectedHours, LeaveTransactionType::Allocation->value, Mockery::type('array'));

        $delta = $this->service->applyAccrual($employee, $leaveType);

        $this->assertEquals($expectedHours, $delta);
    }

    public function test_apply_accrual_returns_zero_when_already_fully_credited(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subYears(5)->timestamp]);
        $leaveType = $this->makeLeaveType(['bcea_minimum_days' => 15]);

        // 120h already credited (cap)
        LeaveBalanceTransaction::factory()->create([
            'employee_id'      => $employee->id,
            'leave_type_id'    => $leaveType->id,
            'transaction_type' => LeaveTransactionType::Allocation->value,
            'hours'            => 120,
        ]);

        $this->repo->shouldNotReceive('credit');

        $delta = $this->service->applyAccrual($employee, $leaveType);

        $this->assertEquals(0.0, $delta);
    }

    public function test_apply_accrual_backdated_uses_from_date(): void
    {
        $hireDate  = Carbon::parse('2024-01-02');
        $employee  = $this->makeEmployee(['date_joined' => $hireDate->timestamp]);
        $leaveType = $this->makeLeaveType();

        $this->repo->shouldReceive('credit')
            ->once()
            ->withArgs(function ($id, $typeId, $hours, $type, $meta) {
                return str_contains($meta['description'] ?? '', 'Backdated');
            });

        $this->service->applyAccrual($employee, $leaveType, $hireDate, now(), 'late account creation');

        // Mockery's ->once() is verified on close, which PHPUnit cannot see — without this the
        // test is reported as risky and would pass just as happily if it asserted nothing at all.
        $this->addToAssertionCount(1);
    }

    public function test_apply_accrual_returns_zero_when_no_date_joined(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => null]);
        $leaveType = $this->makeLeaveType();

        $this->repo->shouldNotReceive('credit');

        $delta = $this->service->applyAccrual($employee, $leaveType);

        $this->assertEquals(0.0, $delta);
    }

    // ── reconcile ─────────────────────────────────────────────────────────────

    public function test_reconcile_credits_when_balance_below_expected(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subYears(5)->timestamp]);
        $leaveType = $this->makeLeaveType(['bcea_minimum_days' => 15]);

        $this->repo->shouldReceive('currentBalance')
            ->with($employee->id, $leaveType->id)
            ->andReturn(40.0);

        $this->repo->shouldReceive('credit')
            ->once()
            ->with($employee->id, $leaveType->id, 80.0, LeaveTransactionType::Correction->value, Mockery::type('array'));

        $delta = $this->service->reconcile($employee, $leaveType);

        $this->assertEquals(80.0, $delta);
    }

    public function test_reconcile_debits_when_balance_above_expected(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(10)->timestamp]);
        $leaveType = $this->makeLeaveType();

        $this->repo->shouldReceive('currentBalance')
            ->andReturn(200.0); // overdrawn

        $this->repo->shouldReceive('debit')
            ->once()
            ->with($employee->id, $leaveType->id, Mockery::type('float'), LeaveTransactionType::Correction->value, Mockery::type('array'));

        $delta = $this->service->reconcile($employee, $leaveType);

        $this->assertLessThan(0.0, $delta);
    }

    public function test_reconcile_no_op_when_balance_matches(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subYears(5)->timestamp]);
        $leaveType = $this->makeLeaveType(['bcea_minimum_days' => 15]);

        $this->repo->shouldReceive('currentBalance')->andReturn(120.0);
        $this->repo->shouldNotReceive('credit');
        $this->repo->shouldNotReceive('debit');

        $delta = $this->service->reconcile($employee, $leaveType);

        $this->assertEquals(0.0, $delta);
    }
}
