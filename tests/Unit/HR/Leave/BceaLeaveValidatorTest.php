<?php

namespace Tests\Unit\HR\Leave;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Contracts\LeaveBalanceRepositoryInterface;
use App\Modules\HR\Leave\Contracts\LeaveRequestRepositoryInterface;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Services\BceaLeaveValidator;
use App\Modules\HR\Leave\Services\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class BceaLeaveValidatorTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestRepositoryInterface $requestRepo;
    private LeaveBalanceRepositoryInterface $balanceRepo;
    private WorkingDaysCalculator           $calculator;
    private BceaLeaveValidator              $validator;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Time is frozen to mid-2025 because the leave dates in these tests are written as fixed
         * 2025 strings.
         *
         * The family-responsibility check counts days used "this cycle", and with no LeaveCycle row
         * it falls back to `whereYear('start_date', now()->year)`. Once the calendar moved past
         * 2025 those requests stopped counting, the entitlement never looked used, and three tests
         * quietly began asserting the opposite of what they were written for.
         */
        Carbon::setTestNow(Carbon::parse('2025-06-01 09:00:00'));

        $this->requestRepo = Mockery::mock(LeaveRequestRepositoryInterface::class);
        $this->balanceRepo = Mockery::mock(LeaveBalanceRepositoryInterface::class);
        $this->calculator  = new WorkingDaysCalculator();

        // Default: no overlapping requests
        $this->requestRepo->shouldReceive('overlapping')->andReturn(collect())->byDefault();

        $this->validator = new BceaLeaveValidator(
            $this->requestRepo,
            $this->balanceRepo,
            $this->calculator,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeEmployee(array $attrs = []): HRPerson
    {
        /*
         * forceFill, because `id` is not fillable.
         *
         * `new HRPerson(['id' => 1, ...])` mass-assigns, and a primary key is guarded — so the id
         * was silently dropped and the validator called currentBalance(null), which its typed
         * signature refuses. The failure surfaced as a Mockery TypeError far from the cause.
         *
         * Saved, too: these tests write leave requests that carry a foreign key to hr_people, so
         * an employee existing only in PHP fails the constraint.
         */
        return tap((new HRPerson())->forceFill(array_merge([
            'id'          => 1,
            'first_name'  => 'Test',
            'surname'     => 'User',
            'date_joined' => Carbon::now()->subYear()->timestamp,
        ], $attrs)), fn (HRPerson $p) => $p->save());
    }

    private function makeLeaveType(array $attrs = []): LeaveType
    {
        return tap((new LeaveType())->forceFill(array_merge([
            'id'            => 1,
            'name'          => 'Annual Leave',
            'statutory_type' => 'annual',
            'is_paid'       => true,
            'require_probation_period_days' => 182,
            'bcea_minimum_days'             => 15,
            'max_consecutive_hours'         => null,
        ], $attrs)), fn (LeaveType $t) => $t->save());
    }

    private function makeDto(array $attrs = []): SubmitLeaveRequestDTO
    {
        return SubmitLeaveRequestDTO::from(array_merge([
            'employee_id'   => 1,
            'leave_type_id' => 1,
            'start_date'    => '2025-06-02',
            'end_date'      => '2025-06-06',
            'request_mode'  => 'daily',
            'start_time'    => null,
            'end_time'      => null,
            'notes'         => null,
            'is_overtime'   => false,
            'is_bulk'       => false,
        ], $attrs));
    }

    // ── 1. Minimum service period ─────────────────────────────────────────────

    public function test_annual_leave_blocked_when_probation_not_complete(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(30)->timestamp]);
        $leaveType = $this->makeLeaveType();
        $dto       = $this->makeDto();

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(120.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('BCEA §20', $result['errors'][0]);
        $this->assertStringContainsString('minimum service period', $result['errors'][0]);
    }

    public function test_annual_leave_allowed_after_probation(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType();
        $dto       = $this->makeDto();

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(120.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_non_annual_leave_ignores_probation(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(30)->timestamp]);
        $leaveType = $this->makeLeaveType(['statutory_type' => 'sick', 'require_probation_period_days' => 182]);
        $dto       = $this->makeDto();

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(120.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        // probation check only applies to annual
        $probationErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'minimum service'));
        $this->assertEmpty($probationErrors);
    }

    // ── 2. Balance validation ─────────────────────────────────────────────────

    public function test_insufficient_balance_returns_error(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType(['require_probation_period_days' => null]);
        $dto       = $this->makeDto(['start_date' => '2025-06-02', 'end_date' => '2025-06-06']); // 5 days = 40h

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(8.0); // only 1 day

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertFalse($result['valid']);
        $balanceErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'Insufficient'));
        $this->assertNotEmpty($balanceErrors);
    }

    public function test_sufficient_balance_passes(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType(['require_probation_period_days' => null]);
        $dto       = $this->makeDto(['start_date' => '2025-06-02', 'end_date' => '2025-06-02']); // 1 day = 8h

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(80.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertTrue($result['valid']);
    }

    // ── 3. Overlap detection ──────────────────────────────────────────────────

    public function test_overlapping_request_returns_error(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType(['require_probation_period_days' => null]);
        $dto       = $this->makeDto();

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(120.0);

        $existing = new LeaveRequest(['request_number' => 'LVR-0001']);
        $this->requestRepo->shouldReceive('overlapping')->andReturn(collect([$existing]));

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertFalse($result['valid']);
        $overlapErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'LVR-0001'));
        $this->assertNotEmpty($overlapErrors);
    }

    // ── 4. Maximum consecutive days ───────────────────────────────────────────

    public function test_exceeding_max_consecutive_adds_warning(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType([
            'require_probation_period_days' => null,
            'max_consecutive_hours'         => 16, // 2 days max
        ]);
        $dto = $this->makeDto(['start_date' => '2025-06-02', 'end_date' => '2025-06-06']); // 5 days

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(120.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $maxWarnings = array_filter($result['warnings'], fn($w) => str_contains($w, 'maximum'));
        $this->assertNotEmpty($maxWarnings);
    }

    // ── 5. Notice period ──────────────────────────────────────────────────────

    public function test_backdated_annual_leave_adds_warning(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(400)->timestamp]);
        $leaveType = $this->makeLeaveType(['require_probation_period_days' => null]);
        $dto       = $this->makeDto(['start_date' => '2020-01-06', 'end_date' => '2020-01-10']); // past

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(120.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $noticeWarnings = array_filter($result['warnings'], fn($w) => str_contains($w, 'Backdated'));
        $this->assertNotEmpty($noticeWarnings);
    }

    public function test_future_annual_leave_has_no_notice_warning(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(400)->timestamp]);
        $leaveType = $this->makeLeaveType(['require_probation_period_days' => null]);
        $dto       = $this->makeDto([
            'start_date' => Carbon::now()->addMonths(2)->format('Y-m-d'),
            'end_date'   => Carbon::now()->addMonths(2)->addDays(4)->format('Y-m-d'),
        ]);

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(120.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $noticeWarnings = array_filter($result['warnings'], fn($w) => str_contains($w, 'Backdated'));
        $this->assertEmpty($noticeWarnings);
    }

    // ── 6. FRL entitlement cap ────────────────────────────────────────────────

    public function test_frl_blocked_when_entitlement_fully_used(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType([
            'statutory_type'                => 'family_responsibility',
            'require_probation_period_days' => null,
            'bcea_minimum_days'             => 3,
        ]);
        $dto = $this->makeDto(['start_date' => '2025-06-02', 'end_date' => '2025-06-02']);

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(24.0);

        // Simulate 3 days already used in cycle
        LeaveRequest::factory()->create([
            'employee_id'   => $employee->id,
            'leave_type_id' => $leaveType->id,
            'status'        => 'approved',
            'start_date'    => '2025-03-01',
            'end_date'      => '2025-03-03',
            'working_days'  => 3,
        ]);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertFalse($result['valid']);
        $frlErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'BCEA §27'));
        $this->assertNotEmpty($frlErrors);
    }

    public function test_frl_blocked_when_request_exceeds_remaining(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType([
            'statutory_type'                => 'family_responsibility',
            'require_probation_period_days' => null,
            'bcea_minimum_days'             => 3,
        ]);
        // Request 2 days when only 1 day remains (2 already used)
        $dto = $this->makeDto(['start_date' => '2025-06-02', 'end_date' => '2025-06-03']);

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(24.0);

        LeaveRequest::factory()->create([
            'employee_id'   => $employee->id,
            'leave_type_id' => $leaveType->id,
            'status'        => 'approved',
            'start_date'    => '2025-02-01',
            'end_date'      => '2025-02-02',
            'working_days'  => 2,
        ]);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertFalse($result['valid']);
        $frlErrors = array_filter($result['errors'], fn($e) => str_contains($e, 'BCEA §27'));
        $this->assertNotEmpty($frlErrors);
    }

    public function test_frl_passes_when_within_entitlement(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType([
            'statutory_type'                => 'family_responsibility',
            'require_probation_period_days' => null,
            'bcea_minimum_days'             => 3,
        ]);
        $dto = $this->makeDto(['start_date' => '2025-06-02', 'end_date' => '2025-06-02']); // 1 day, 0 used

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(24.0);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertTrue($result['valid']);
    }

    public function test_frl_warning_when_partial_used(): void
    {
        $employee  = $this->makeEmployee(['date_joined' => Carbon::now()->subDays(200)->timestamp]);
        $leaveType = $this->makeLeaveType([
            'statutory_type'                => 'family_responsibility',
            'require_probation_period_days' => null,
            'bcea_minimum_days'             => 3,
        ]);
        $dto = $this->makeDto(['start_date' => '2025-06-02', 'end_date' => '2025-06-02']); // 1 day

        $this->balanceRepo->shouldReceive('currentBalance')->andReturn(24.0);

        // 1 day already used — request fits (1+1=2 ≤ 3) but shows warning
        LeaveRequest::factory()->create([
            'employee_id'   => $employee->id,
            'leave_type_id' => $leaveType->id,
            'status'        => 'approved',
            'start_date'    => '2025-01-15',
            'end_date'      => '2025-01-15',
            'working_days'  => 1,
        ]);

        $result = $this->validator->validate($employee, $leaveType, $dto);

        $this->assertTrue($result['valid']);
        $frlWarnings = array_filter($result['warnings'], fn($w) => str_contains($w, 'FRL:'));
        $this->assertNotEmpty($frlWarnings);
    }
}
