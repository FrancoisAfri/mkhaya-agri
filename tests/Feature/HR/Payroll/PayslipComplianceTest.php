<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\HR\Payroll\Support\LabourRates;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the payslip has to say, and the statutory figures behind it.
 *
 * The payslip named the software vendor as the employer on every document it had ever issued, and
 * the lump-sum tables it taxes retrenchments against were empty — so a severance payment came out
 * taxed at nothing, with no error and no warning.
 */
class PayslipComplianceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        app(CurrentCompany::class)->set((int) $companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
    }

    // ── The lump-sum tables that were empty ──────────────────────────────────

    public function test_every_tax_year_has_a_severance_table(): void
    {
        $empty = DB::table('sa_tax_years')
            ->where(fn ($q) => $q->whereNull('lump_sum_severance')->orWhereRaw("lump_sum_severance::text IN ('[]','null','')"))
            ->count();

        $this->assertSame(0, $empty, 'A year with no severance table taxes a retrenchment at zero.');
    }

    public function test_a_severance_below_the_free_band_is_not_taxed(): void
    {
        $engine = app(\App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::class);
        $year = DB::table('sa_tax_years')->max('tax_year');

        // The first R550 000 of a retrenchment benefit is tax free.
        $this->assertSame(0.0, $engine->severancePaye(400000, (int) $year));
    }

    public function test_a_severance_above_the_free_band_is_taxed_at_eighteen_percent(): void
    {
        $engine = app(\App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::class);
        $year = DB::table('sa_tax_years')->max('tax_year');

        // R700 000 is R150 000 over the free band, at 18%.
        $this->assertSame(27000.0, $engine->severancePaye(700000, (int) $year));
    }

    public function test_severance_and_withdrawal_are_not_the_same_table(): void
    {
        $engine = app(\App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::class);
        $year = (int) DB::table('sa_tax_years')->max('tax_year');

        // A resignation withdrawal is free only to R27 500, a retrenchment to R550 000. Taxing a
        // retrenchment on the withdrawal table would take money from somebody who just lost a job.
        $this->assertGreaterThan(
            $engine->severancePaye(300000, $year),
            $engine->withdrawalPaye(300000, $year),
        );
    }

    // ── Labour rates as data ─────────────────────────────────────────────────

    public function test_the_minimum_wage_is_read_by_date_not_hard_coded(): void
    {
        $rates = app(LabourRates::class);

        $now = $rates->minimumMonthlyWage('2025-06-01');
        $then = $rates->minimumMonthlyWage('2024-06-01');

        $this->assertGreaterThan(0, $now);
        // A payslip reprinted for an old period must be measured against the rate that applied then.
        $this->assertNotEquals($now, $then);
    }

    public function test_the_bcea_multipliers_are_available(): void
    {
        $rates = app(LabourRates::class);

        $this->assertSame(1.5, $rates->overtimeMultiplier());   // §10
        $this->assertSame(2.0, $rates->sundayMultiplier());      // §16
        $this->assertSame(2.0, $rates->publicHolidayMultiplier()); // §18
    }

    public function test_a_date_with_no_determination_does_not_pretend_to_have_one(): void
    {
        // Long before the National Minimum Wage Act. Returning a made-up figure here would flag an
        // entire historic payroll as underpaying.
        $this->assertSame(0.0, app(LabourRates::class)->minimumMonthlyWage('2010-01-01'));
    }

    public function test_the_coida_ceiling_is_available_for_the_return_of_earnings(): void
    {
        $this->assertGreaterThan(0, app(LabourRates::class)->coidaCeiling('2025-06-01'));
    }

    // ── The payslip names the employer ───────────────────────────────────────

    public function test_the_payslip_no_longer_hard_codes_the_vendor_name(): void
    {
        $blade = file_get_contents(resource_path('views/pdf/payslip.blade.php'));

        // It said "MkhayaHub" — the software vendor — on every payslip ever issued.
        $this->assertStringNotContainsString('>MkhayaHub<', $blade);
        $this->assertStringContainsString("\$employer['name']", $blade);
    }

    public function test_the_payslip_states_what_bcea_section_33_requires(): void
    {
        $blade = file_get_contents(resource_path('views/pdf/payslip.blade.php'));

        foreach ([
            "\$employer['address']",     // §33(1)(a)
            "\$employee['occupation']",  // §33(1)(b)
            'pay_date',
            "\$ytd[",                    // not required, but expected on every SA payslip
            "\$hours[",                  // §33(1)(g), rendered only when hours exist
        ] as $needle) {
            $this->assertStringContainsString($needle, $blade, "The payslip is missing {$needle}.");
        }
    }

    public function test_the_view_data_names_a_real_employer(): void
    {
        $payslip = \App\Modules\HR\Payroll\Models\Payslip::query()->first();

        if (! $payslip) {
            $this->markTestSkipped('No payslip in the test database.');
        }

        $data = app(\App\Modules\HR\Payroll\Services\PayrollService::class)->payslipViewData($payslip);

        $this->assertArrayHasKey('employer', $data);
        $this->assertNotEmpty($data['employer']['name']);
        $this->assertArrayHasKey('ytd', $data);
        $this->assertArrayHasKey('hours', $data);
    }
}
