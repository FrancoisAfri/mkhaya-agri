<?php

namespace Tests\Unit\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use App\Modules\Finance\StaffLoan\Services\StaffLoanService;
use Database\Factories\Finance\SalaryAdvanceFactory;
use Database\Factories\Finance\StaffLoanFactory;
use Database\Factories\Finance\StaffLoanTypeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffLoanServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffLoanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StaffLoanService();
    }

    // ── calculateLoanRepayment ────────────────────────────────────────────────

    public function test_no_interest_divides_principal_by_term(): void
    {
        $result = $this->service->calculateLoanRepayment(12000, 0, 12, 'none');

        $this->assertEquals(1000.00, $result);
    }

    public function test_fixed_interest_includes_full_interest_spread_evenly(): void
    {
        // (12000 + 12000 * 0.12 * 1) / 12 = 13440 / 12 = 1120.00
        $result = $this->service->calculateLoanRepayment(12000, 12, 12, 'fixed');

        $this->assertEquals(1120.00, $result);
    }

    public function test_reducing_balance_uses_amortisation_formula(): void
    {
        // principal=10000, annual rate=12%, term=12 → ~888.49
        $result = $this->service->calculateLoanRepayment(10000, 12, 12, 'reducing_balance');

        $this->assertEquals(888.49, $result);
    }

    public function test_zero_rate_falls_back_to_simple_division(): void
    {
        // rate=0 with reducing_balance type → falls back to principal / term
        $result = $this->service->calculateLoanRepayment(6000, 0, 6, 'reducing_balance');

        $this->assertEquals(1000.00, $result);
    }

    // ── runAffordabilityCheck ─────────────────────────────────────────────────

    public function test_affordability_passes_when_ratio_under_25_percent(): void
    {
        $employee = User::factory()->create(['status' => 1]);

        $loanType = StaffLoanTypeFactory::new()->create([
            'interest_rate' => 0,
            'interest_type' => 'none',
        ]);

        // monthly_installment for 10000 / 12 = 833.33 — well under 25% of 20000
        $loan = StaffLoanFactory::new()->create([
            'employee_id'         => $employee->id,
            'loan_type_id'        => $loanType->id,
            'amount_requested'    => 10000,
            'term_months'         => 12,
            'monthly_net_pay'     => 20000,
            'monthly_installment' => 833.33,
            'status'              => 'pending',
        ]);

        $check = $this->service->runAffordabilityCheck($loan, 20000);

        $this->assertTrue($check->passed);
    }

    public function test_affordability_fails_when_ratio_exceeds_25_percent(): void
    {
        $employee = User::factory()->create(['status' => 1]);

        $loanType = StaffLoanTypeFactory::new()->create([
            'interest_rate' => 0,
            'interest_type' => 'none',
        ]);

        // monthly_installment = 3000 → 30% of 10000 → fails 25% threshold
        $loan = StaffLoanFactory::new()->create([
            'employee_id'         => $employee->id,
            'loan_type_id'        => $loanType->id,
            'amount_requested'    => 3000,
            'term_months'         => 1,
            'monthly_net_pay'     => 10000,
            'monthly_installment' => 3000,
            'status'              => 'pending',
        ]);

        $check = $this->service->runAffordabilityCheck($loan, 10000);

        $this->assertFalse($check->passed);
    }

    // ── runAdvanceAffordabilityCheck ──────────────────────────────────────────

    public function test_advance_affordability_passes_when_under_30_percent(): void
    {
        $employee = User::factory()->create(['status' => 1]);

        // 5000 / 20000 = 25% → passes 30% threshold
        $advance = SalaryAdvanceFactory::new()->create([
            'employee_id'      => $employee->id,
            'amount_requested' => 5000,
            'monthly_net_pay'  => 20000,
            'status'           => 'pending',
        ]);

        $check = $this->service->runAdvanceAffordabilityCheck($advance, 20000);

        $this->assertTrue($check->passed);
    }

    public function test_advance_affordability_fails_when_over_30_percent(): void
    {
        $employee = User::factory()->create(['status' => 1]);

        // 7000 / 20000 = 35% → fails 30% threshold
        $advance = SalaryAdvanceFactory::new()->create([
            'employee_id'      => $employee->id,
            'amount_requested' => 7000,
            'monthly_net_pay'  => 20000,
            'status'           => 'pending',
        ]);

        $check = $this->service->runAdvanceAffordabilityCheck($advance, 20000);

        $this->assertFalse($check->passed);
    }
}
