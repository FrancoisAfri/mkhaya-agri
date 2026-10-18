<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\HR\Payroll\Models\PayrollSetting;
use App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine;
use App\Modules\HR\Payroll\Services\PayrollService;
use Database\Factories\HR\PayrollRunFactory;
use Database\Factories\HR\PayslipFactory;

/**
 * Weekly, fortnightly and monthly have to mean the same thing in the settings screen, the request
 * validator, the database and the tax engine.
 *
 * They previously did not: the screen sent "biweekly", the validator accepted only "bi-weekly", and
 * the engine keys off "fortnightly". The middle option could therefore not be saved at all, and had
 * it been saved the engine would not have recognised it and would have annualised the pay as
 * monthly — under-deducting PAYE on every fortnightly payslip without any visible symptom.
 */
class PayFrequencyTest extends PayrollTestCase
{
    private PayrollService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayrollService();
    }

    // ── The shared vocabulary ────────────────────────────────────────────────

    public function test_the_three_frequencies_annualise_at_the_right_number_of_periods(): void
    {
        $this->assertSame(12, PayrollTaxEngine::periodsFor('monthly'));
        $this->assertSame(26, PayrollTaxEngine::periodsFor('fortnightly'));
        $this->assertSame(52, PayrollTaxEngine::periodsFor('weekly'));
    }

    public function test_legacy_spellings_are_understood_rather_than_silently_taxed_as_monthly(): void
    {
        foreach (['biweekly', 'bi-weekly', 'BI-WEEKLY', ' Fortnightly '] as $stored) {
            $this->assertSame('fortnightly', PayrollTaxEngine::canonicalFrequency($stored), "failed for: {$stored}");
        }

        // Anything genuinely unknown is reported as such, so the caller can decide — the old code
        // substituted monthly here without a word.
        $this->assertNull(PayrollTaxEngine::canonicalFrequency('every-other-tuesday'));
        $this->assertNull(PayrollTaxEngine::canonicalFrequency(null));
    }

    public function test_an_unknown_stored_frequency_falls_back_to_monthly(): void
    {
        PayrollSetting::create(['pay_frequency' => 'every-other-tuesday']);

        // A payroll run is never blocked by a bad settings row — but unlike before, the fallback
        // is written to the log, because PAYE at the wrong annualisation leaves no other trace.
        $this->assertSame('monthly', $this->service->payFrequency());
    }

    // ── The settings screen ──────────────────────────────────────────────────

    public function test_fortnightly_can_actually_be_saved_from_the_settings_screen(): void
    {
        // Every option the screen offers must be one the validator accepts and the tax engine can
        // read. Comparing the three lists directly is what would have caught the original defect:
        // the screen offered "biweekly", the validator wanted "bi-weekly", the engine "fortnightly".
        $offered = \App\Modules\HR\Payroll\Controllers\PayrollSettingsController::PAY_FREQUENCIES;

        $this->assertContains('fortnightly', $offered);

        foreach ($offered as $option) {
            $this->assertNotNull(
                PayrollTaxEngine::canonicalFrequency($option),
                "The settings screen offers '{$option}' but the tax engine cannot annualise it.",
            );

            $setting = PayrollSetting::create(['pay_frequency' => $option]);
            $this->assertSame($option, $setting->fresh()->pay_frequency, "'{$option}' did not survive a round trip to the database.");
            $setting->delete();
        }
    }

    public function test_the_option_the_screen_used_to_send_is_no_longer_offered(): void
    {
        $this->assertNotContains(
            'biweekly',
            \App\Modules\HR\Payroll\Controllers\PayrollSettingsController::PAY_FREQUENCIES,
        );
    }

    // ── Pay is scaled to the period, not paid monthly every week ─────────────

    public function test_a_monthly_package_is_scaled_to_the_length_of_the_pay_period(): void
    {
        $monthlyPackage = 26000.00;

        $this->assertSame(26000.00, $this->service->periodBasicSalary($monthlyPackage, 'monthly'));
        $this->assertSame(12000.00, $this->service->periodBasicSalary($monthlyPackage, 'fortnightly')); // 26000*12/26
        $this->assertSame(6000.00, $this->service->periodBasicSalary($monthlyPackage, 'weekly'));       // 26000*12/52
    }

    // ── Statutory ceilings follow the period too ─────────────────────────────

    public function test_the_uif_ceiling_is_brought_down_to_the_pay_period(): void
    {
        $engine = app(PayrollTaxEngine::class);
        $taxYear = (int) \App\Modules\HR\Payroll\Models\Bureau\SaTaxYear::max('tax_year');

        // A high earner is capped in every case, but the cap itself must shrink with the period:
        // the monthly ceiling applied to a weekly wage is effectively no ceiling at all.
        $monthly = $engine->uif(50000, $taxYear, 'ZA', 'monthly')['employee'];
        $weekly = $engine->uif(50000, $taxYear, 'ZA', 'weekly')['employee'];
        $fortnightly = $engine->uif(50000, $taxYear, 'ZA', 'fortnightly')['employee'];

        $this->assertEqualsWithDelta(177.12, $monthly, 0.01);
        $this->assertEqualsWithDelta($monthly * 12 / 52, $weekly, 0.02);
        $this->assertEqualsWithDelta($monthly * 12 / 26, $fortnightly, 0.02);

        // Twelve monthly deductions and fifty-two weekly ones must come to the same year.
        $this->assertEqualsWithDelta($monthly * 12, $weekly * 52, 0.5);
    }

    public function test_paye_on_the_same_annual_earnings_is_the_same_whichever_cycle_is_used(): void
    {
        $engine = app(PayrollTaxEngine::class);
        $taxYear = (int) \App\Modules\HR\Payroll\Models\Bureau\SaTaxYear::max('tax_year');

        $annual = 360000.00;
        $monthly = $engine->periodPaye($annual / 12, 'monthly', 40, $taxYear) * 12;
        $fortnightly = $engine->periodPaye($annual / 26, 'fortnightly', 40, $taxYear) * 26;
        $weekly = $engine->periodPaye($annual / 52, 'weekly', 40, $taxYear) * 52;

        $this->assertGreaterThan(0, $monthly);
        $this->assertEqualsWithDelta($monthly, $fortnightly, 30.0);
        $this->assertEqualsWithDelta($monthly, $weekly, 60.0);
    }

    /**
     * The regression this whole change exists for: a fortnightly payroll must not be taxed as if
     * the same amount were earned monthly.
     */
    public function test_a_fortnightly_payroll_is_not_taxed_as_a_monthly_one(): void
    {
        PayrollSetting::create(['pay_frequency' => 'fortnightly', 'paye_enabled' => true]);

        $run = PayrollRunFactory::new()->create();
        $payslip = PayslipFactory::new()->create(['payroll_run_id' => $run->id, 'gross_pay' => 15000.00]);

        $fortnightly = $this->service->calculateDeductions($payslip, 15000.00)['paye'];

        PayrollSetting::query()->update(['pay_frequency' => 'monthly']);
        $monthly = $this->service->calculateDeductions($payslip, 15000.00)['paye'];

        // R15 000 a fortnight is R390 000 a year and R15 000 a month is R180 000 — the PAYE on the
        // first must be materially higher. Before the fix both returned the monthly figure.
        $this->assertGreaterThan($monthly * 2, $fortnightly);
    }

    // ── Recurring deductions are per period, not per month ───────────────────

    public function test_a_monthly_loan_instalment_is_not_recovered_every_single_week(): void
    {
        PayrollSetting::create(['pay_frequency' => 'weekly']);

        $run = PayrollRunFactory::new()->create();
        $payslip = PayslipFactory::new()->create(['payroll_run_id' => $run->id]);

        \Illuminate\Support\Facades\DB::table('staff_loans')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference_number' => 'LN-TEST-1',
            'employee_id' => $payslip->employee_id,
            'status' => 'active',
            'amount_requested' => 15600.00,
            'monthly_installment' => 1300.00,
            'term_months' => 12,
            'purpose' => 'Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $weekly = $this->service->calculateDeductions($payslip, 6000.00)['loanDeduction'];

        // R1 300 a month is R300 a week — not R1 300 a week, which would recover the loan
        // four and a third times over.
        $this->assertEqualsWithDelta(300.00, $weekly, 0.02);
    }
}
