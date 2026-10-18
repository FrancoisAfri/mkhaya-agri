<?php

namespace Tests\Unit\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Services\PayrollService;
use Database\Factories\HR\PayrollRunFactory;
use Database\Factories\HR\PayslipFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollServiceTest extends TestCase
{
    use RefreshDatabase;

    private PayrollService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayrollService();
    }

    // ── UIF cap ───────────────────────────────────────────────────────────────

    public function test_uif_is_capped_at_statutory_maximum(): void
    {
        $run     = PayrollRunFactory::new()->create();
        $payslip = PayslipFactory::new()->create([
            'payroll_run_id' => $run->id,
            'gross_pay'      => 50000.00, // 1% = 500, but capped at 177.12
        ]);

        $deductions = $this->service->calculateDeductions($payslip, 50000.00);

        $this->assertEquals(177.12, $deductions['uif']);
    }

    public function test_uif_is_not_capped_for_low_earner(): void
    {
        $run     = PayrollRunFactory::new()->create();
        $payslip = PayslipFactory::new()->create([
            'payroll_run_id' => $run->id,
            'gross_pay'      => 10000.00, // 1% = 100, under cap
        ]);

        $deductions = $this->service->calculateDeductions($payslip, 10000.00);

        $this->assertEquals(100.00, $deductions['uif']);
    }

    // ── PAYE ─────────────────────────────────────────────────────────────────

    public function test_paye_crosses_into_the_second_bracket(): void
    {
        // R20 000 a month is R240 000 a year, which is R2 900 over the top of the 18% bracket — so
        // the arithmetic is not one rate applied to everything:
        //
        //   first R237 100 @ 18%            = 42 678
        //   the remaining R2 900 @ 26%      =    754
        //   annual tax                      = 43 432
        //   less the primary rebate         = 43 432 - 17 235 = 26 197
        //   monthly                         = 26 197 / 12     =  2 183.08
        //
        // This test used to expect R2 163.75, which is 18% of everything with the rebate applied as
        // a threshold deduction. That agrees with the real answer only while the income stays inside
        // the first bracket, and it stopped agreeing the moment the engine started reading the real
        // bracket table. The engine was right and the expectation was stale.
        $run     = PayrollRunFactory::new()->create();
        $payslip = PayslipFactory::new()->create([
            'payroll_run_id' => $run->id,
            'gross_pay'      => 20000.00,
        ]);

        $deductions = $this->service->calculateDeductions($payslip, 20000.00);

        $this->assertEquals(2183.08, $deductions['paye']);
    }

    public function test_paye_is_zero_below_annual_threshold(): void
    {
        // annual gross = 12 * 7000 = 84000, below threshold of 95750
        $run     = PayrollRunFactory::new()->create();
        $payslip = PayslipFactory::new()->create([
            'payroll_run_id' => $run->id,
            'gross_pay'      => 7000.00,
        ]);

        $deductions = $this->service->calculateDeductions($payslip, 7000.00);

        $this->assertEquals(0.00, $deductions['paye']);
    }

    // ── Net pay ───────────────────────────────────────────────────────────────

    public function test_net_pay_equals_gross_minus_deductions(): void
    {
        $grossPay        = 22000.00;
        $totalDeductions = 5000.00;

        $netPay = $this->service->calculateNetPay($grossPay, $totalDeductions);

        $this->assertEquals(17000.00, $netPay);
    }

    public function test_net_pay_rounds_to_two_decimal_places(): void
    {
        $netPay = $this->service->calculateNetPay(10000.005, 0.005);

        $this->assertEquals(round(10000.005 - 0.005, 2), $netPay);
    }

    // ── createPayrollRun ref number ───────────────────────────────────────────

    public function test_create_payroll_run_generates_ref_number(): void
    {
        $creator = User::factory()->create(['status' => 1]);

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd   = now()->endOfMonth()->toDateString();

        $run = $this->service->createPayrollRun([
            'name'         => 'Test Payroll Run',
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
        ], $creator->id);

        $this->assertNotNull($run->ref_number);
        $this->assertStringStartsWith('PR-', $run->ref_number);
        $this->assertEquals(PayrollRunStatus::Draft, $run->status);
        $this->assertEquals($creator->id, $run->created_by);
        $this->assertDatabaseHas('payroll_runs', [
            'id'         => $run->id,
            'ref_number' => $run->ref_number,
            'created_by' => $creator->id,
        ]);
    }

    public function test_second_payroll_run_for_same_period_increments_sequence(): void
    {
        $creator = User::factory()->create(['status' => 1]);
        $period  = ['period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString()];

        $run1 = $this->service->createPayrollRun(array_merge($period, ['name' => 'Run 1']), $creator->id);
        $run2 = $this->service->createPayrollRun(array_merge($period, ['name' => 'Run 2']), $creator->id);

        $this->assertNotEquals($run1->ref_number, $run2->ref_number);
    }
}
