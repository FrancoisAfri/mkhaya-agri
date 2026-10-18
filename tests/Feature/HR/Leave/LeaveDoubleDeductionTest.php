<?php

namespace Tests\Feature\HR\Leave;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeaveDoubleDeductionTest extends TestCase
{
    use DatabaseTransactions;

    private int $employeeId;
    private LeaveType $type;
    private LeaveBalanceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(LeaveBalanceService::class);
        $this->type = LeaveType::create(['name' => 'Annual ' . uniqid(), 'status' => 1]);
        $person = HRPerson::create(['uuid' => (string) Str::uuid(), 'first_name' => 'Leave', 'surname' => 'Tester', 'status' => 1, 'email' => 'lt' . uniqid() . '@x.com']);
        $this->employeeId = $person->id; // isolated ledger
    }

    private function balance(): float
    {
        return $this->svc->getBalance($this->employeeId, $this->type->id);
    }

    private function request(): LeaveRequest
    {
        return LeaveRequest::create([
            'uuid' => (string) Str::uuid(), 'request_number' => 'LR-TEST-' . uniqid(),
            'employee_id' => $this->employeeId, 'leave_type_id' => $this->type->id,
            'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(),
            'working_hours' => 8, 'hours_reserved' => 8, 'leave_cycle_id' => null,
        ]);
    }

    public function test_approval_confirms_reservation_without_deducting_twice(): void
    {
        $this->svc->manualAdjust($this->employeeId, $this->type->id, 160, 'opening'); // 20 days
        $this->assertEquals(160.0, $this->balance());

        $req = $this->request();
        $this->svc->reserve($req);
        $this->assertEquals(152.0, $this->balance());   // one day reserved

        $before = LeaveBalanceTransaction::where('employee_id', $this->employeeId)->count();
        $this->svc->consume($req);

        // Balance must NOT drop again — it stays at 152, not 144.
        $this->assertEquals(152.0, $this->balance());
        // No new ledger row: the reservation was converted in place.
        $this->assertEquals($before, LeaveBalanceTransaction::where('employee_id', $this->employeeId)->count());
        // …and that row is now a consumption.
        $this->assertDatabaseHas('leave_balance_transactions', [
            'reference_id' => $req->id,
            'transaction_type' => LeaveTransactionType::RequestConsumption->value,
        ]);
        $this->assertDatabaseMissing('leave_balance_transactions', [
            'reference_id' => $req->id,
            'transaction_type' => LeaveTransactionType::RequestReservation->value,
        ]);
    }

    public function test_reject_releases_the_reservation(): void
    {
        $this->svc->manualAdjust($this->employeeId, $this->type->id, 160, 'opening');
        $req = $this->request();
        $this->svc->reserve($req);
        $this->assertEquals(152.0, $this->balance());

        $this->svc->release($req);
        $this->assertEquals(160.0, $this->balance());   // fully restored
    }

    public function test_admin_cancel_of_approved_restores_balance_once(): void
    {
        $this->svc->manualAdjust($this->employeeId, $this->type->id, 160, 'opening');
        $req = $this->request();
        $this->svc->reserve($req);
        $this->svc->consume($req);
        $this->assertEquals(152.0, $this->balance());

        $this->svc->releaseApproved($req);
        $this->assertEquals(160.0, $this->balance());   // back to full, not 168 (no over-credit)
    }
}
