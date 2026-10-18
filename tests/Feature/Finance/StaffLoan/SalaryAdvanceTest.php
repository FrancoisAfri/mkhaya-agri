<?php

namespace Tests\Feature\Finance\StaffLoan;

use Database\Factories\Finance\SalaryAdvanceFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Finance\FinanceTestCase;

class SalaryAdvanceTest extends FinanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Activate the staff_loans module in addition to claims/petty_cash
        // that FinanceTestCase already seeds.
        $categoryId = DB::table('security_categories')
            ->where('name', 'Finance')
            ->value('id');

        DB::table('security_modules')->insertOrIgnore([[
            'category_id' => $categoryId,
            'uuid'        => (string) Str::uuid(),
            'name'        => 'Staff Loans',
            'path'        => 'staff-loans',
            'code_name'   => 'staff_loans',
            'active'      => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]]);

        Cache::forget('module_active_staff_loans');
    }

    // ── Submission ────────────────────────────────────────────────────────────

    public function test_employee_can_submit_salary_advance_request(): void
    {
        $this->actingAs($this->employee)
            ->post(route('staff-loans.advances.store'), [
                'amount_requested' => 3000,
                'monthly_net_pay'  => 20000,
                'repayment_month'  => now()->addMonth()->startOfMonth()->toDateString(),
                'reason'           => 'Emergency expense',
                'is_permanent'     => true,
                'is_suspended'     => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('salary_advances', [
            'employee_id'      => $this->employee->id,
            'amount_requested' => 3000,
            'status'           => 'pending',
        ]);
    }

    public function test_advance_validates_required_fields(): void
    {
        $this->actingAs($this->employee)
            ->post(route('staff-loans.advances.store'), [
                'amount_requested' => 3000,
                'repayment_month'  => now()->addMonth()->startOfMonth()->toDateString(),
                // Missing 'reason'
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_advance_repayment_month_must_be_future(): void
    {
        $this->actingAs($this->employee)
            ->post(route('staff-loans.advances.store'), [
                'amount_requested' => 3000,
                'monthly_net_pay'  => 20000,
                'repayment_month'  => now()->subDay()->toDateString(),
                'reason'           => 'Emergency expense',
                'is_permanent'     => true,
                'is_suspended'     => false,
            ])
            ->assertSessionHasErrors('repayment_month');
    }

    // ── Approval workflow ─────────────────────────────────────────────────────

    public function test_finance_manager_can_approve_advance(): void
    {
        $advance = SalaryAdvanceFactory::new()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.advances.approve', $advance->uuid), [
                'comments' => 'Approved.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('salary_advances', [
            'id'     => $advance->id,
            'status' => 'approved',
        ]);
    }

    public function test_finance_manager_can_reject_advance(): void
    {
        $advance = SalaryAdvanceFactory::new()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.advances.reject', $advance->uuid), [
                'reason' => 'Advance limit exceeded.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('salary_advances', [
            'id'     => $advance->id,
            'status' => 'rejected',
        ]);
    }

    // ── Disburse ──────────────────────────────────────────────────────────────

    public function test_finance_manager_can_disburse_approved_advance(): void
    {
        $advance = SalaryAdvanceFactory::new()->approved()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.advances.disburse', $advance->uuid))
            ->assertRedirect();

        $this->assertDatabaseHas('salary_advances', [
            'id'     => $advance->id,
            'status' => 'disbursed',
        ]);
    }

    // ── Settle ────────────────────────────────────────────────────────────────

    public function test_finance_manager_can_settle_disbursed_advance(): void
    {
        $advance = SalaryAdvanceFactory::new()->disbursed()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.advances.settle', $advance->uuid))
            ->assertRedirect();

        $this->assertDatabaseHas('salary_advances', [
            'id'     => $advance->id,
            'status' => 'settled',
        ]);
    }
}
