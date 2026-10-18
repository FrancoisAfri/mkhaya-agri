<?php

namespace Tests\Feature\Finance\StaffLoan;

use App\Modules\Finance\StaffLoan\Models\StaffLoanRepayment;
use Database\Factories\Finance\StaffLoanFactory;
use Database\Factories\Finance\StaffLoanTypeFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Finance\FinanceTestCase;

class StaffLoanTest extends FinanceTestCase
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

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get(route('staff-loans.loans.index'))
            ->assertRedirect();
    }

    public function test_employee_can_view_loan_index(): void
    {
        $this->actingAs($this->employee)
            ->get(route('staff-loans.loans.index'))
            ->assertStatus(200);
    }

    public function test_finance_manager_sees_all_loans(): void
    {
        StaffLoanFactory::new()->count(3)->create();

        $this->actingAs($this->financeManager)
            ->get(route('staff-loans.loans.index'))
            ->assertStatus(200);
    }

    // ── Loan Application ──────────────────────────────────────────────────────

    public function test_employee_can_submit_loan_application(): void
    {
        StaffLoanTypeFactory::new()->create();

        $this->actingAs($this->employee)
            ->post(route('staff-loans.loans.store'), [
                'amount_requested'    => 10000,
                'term_months'         => 12,
                'purpose'             => 'Home improvement',
                'monthly_net_pay'     => 25000,
                'is_permanent'        => true,
                'probation_completed' => true,
                'is_suspended'        => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('staff_loans', [
            'employee_id'     => $this->employee->id,
            'amount_requested'=> 10000,
            'status'          => 'pending',
        ]);
    }

    public function test_loan_requires_amount_purpose_and_term(): void
    {
        $this->actingAs($this->employee)
            ->post(route('staff-loans.loans.store'), [])
            ->assertSessionHasErrors(['amount_requested', 'term_months', 'purpose']);
    }

    public function test_loan_amount_must_be_within_range(): void
    {
        $this->actingAs($this->employee)
            ->post(route('staff-loans.loans.store'), [
                'amount_requested' => 50,
                'term_months'      => 12,
                'purpose'          => 'Something small',
            ])
            ->assertSessionHasErrors('amount_requested');
    }

    // ── Approval workflow ─────────────────────────────────────────────────────

    public function test_finance_manager_can_approve_a_loan(): void
    {
        $loan = StaffLoanFactory::new()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.loans.approve', $loan->uuid), [
                'level'    => 'finance',
                'comments' => 'Approved after review.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('staff_loans', [
            'id'     => $loan->id,
            'status' => 'approved',
        ]);
    }

    public function test_finance_manager_can_reject_a_loan(): void
    {
        $loan = StaffLoanFactory::new()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.loans.reject', $loan->uuid), [
                'reason' => 'Does not meet policy requirements.',
                'level'  => 'finance',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('staff_loans', [
            'id'     => $loan->id,
            'status' => 'rejected',
        ]);
    }

    // ── Disburse ──────────────────────────────────────────────────────────────

    public function test_finance_manager_can_disburse_approved_loan(): void
    {
        $loan = StaffLoanFactory::new()->approved()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.loans.disburse', $loan->uuid), [
                'amount_approved' => 10000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('staff_loans', [
            'id'     => $loan->id,
            'status' => 'active',
        ]);
    }

    // ── Repayment ─────────────────────────────────────────────────────────────

    public function test_finance_manager_can_record_a_repayment(): void
    {
        $loan = StaffLoanFactory::new()->active()->create(['employee_id' => $this->employee->id]);

        // Create a pending repayment so that recordRepayment() has a row to work with.
        StaffLoanRepayment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 1,
            'due_date'           => now()->addMonth(),
            'amount_due'         => $loan->monthly_installment ?? 888.49,
            'status'             => 'pending',
        ]);

        $this->actingAs($this->financeManager)
            ->post(route('staff-loans.loans.repayment', $loan->uuid), [
                'amount' => 888.49,
                'method' => 'payroll',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('staff_loan_repayments', [
            'loan_id' => $loan->id,
            'status'  => 'paid',
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_employee_can_view_own_loan(): void
    {
        $loan = StaffLoanFactory::new()->create(['employee_id' => $this->employee->id]);

        $this->actingAs($this->employee)
            ->get(route('staff-loans.loans.show', $loan->uuid))
            ->assertStatus(200);
    }
}
