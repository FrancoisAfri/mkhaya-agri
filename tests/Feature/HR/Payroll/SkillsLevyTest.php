<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Services\SkillsLevyService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Skills Development Levy, and the fifth of it that can be claimed back.
 *
 * Payroll paid SDL every month with the EMP201. The WSP and ATR that reclaim the mandatory grant
 * live in the LMS, which knew the 30 April deadline existed but never knew what had actually been
 * levied — so nobody could answer "how much of last year's levy did we get back?", and the grant
 * went unclaimed with no one noticing.
 */
class SkillsLevyTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        DB::table('sa_tax_years')->updateOrInsert(
            ['country' => 'ZA', 'tax_year' => 2027],
            [
                'starts_on' => '2026-03-01', 'ends_on' => '2027-02-28',
                'brackets' => json_encode([[0, 0.18, 0]]),
                'primary_rebate' => 17235, 'secondary_rebate' => 9444, 'tertiary_rebate' => 3145,
                'threshold_under_65' => 95750, 'threshold_65_to_74' => 148217, 'threshold_75_plus' => 165689,
                'medical_credit_main' => 364, 'medical_credit_first_dependant' => 364,
                'medical_credit_additional' => 246,
                'uif_rate' => 0.01, 'uif_monthly_ceiling' => 17712,
                'sdl_rate' => 0.01, 'sdl_annual_threshold' => 500000,
                'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    /** Gross pay inside the April–March levy year. */
    private function paid(float $gross, string $periodEnd = '2026-09-30'): void
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'sdl' . Str::random(6) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        $runId = DB::table('payroll_runs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Run ' . Str::random(4),
            'period_start' => date('Y-m-01', strtotime($periodEnd)), 'period_end' => $periodEnd,
            'status' => 'draft', 'created_by' => $user->id, 'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('payslips')->insert([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $runId, 'employee_id' => $user->id,
            'status' => 'draft', 'basic_salary' => $gross, 'gross_pay' => $gross,
            'total_deductions' => 0, 'net_pay' => $gross,
            'period_start' => date('Y-m-01', strtotime($periodEnd)), 'period_end' => $periodEnd,
            'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function report(int $year = 2027): array
    {
        return app(SkillsLevyService::class)->forYear($year, $this->companyId);
    }

    // ── The levy ─────────────────────────────────────────────────────────────

    public function test_the_levy_year_runs_april_to_march(): void
    {
        $report = $this->report();

        // The WSP cycle, not the tax year.
        $this->assertSame('2026-04-01', $report['period']['from']);
        $this->assertSame('2027-03-31', $report['period']['to']);
    }

    public function test_pay_outside_the_levy_year_is_not_counted(): void
    {
        $this->paid(600000, '2026-09-30');
        $this->paid(900000, '2026-03-31');   // the previous levy year

        $this->assertEqualsWithDelta(600000, $this->report()['leviable_payroll'], 0.01);
    }

    public function test_an_employer_over_the_threshold_pays_one_percent(): void
    {
        $this->paid(600000);

        $report = $this->report();

        $this->assertFalse($report['exempt']);
        $this->assertEqualsWithDelta(6000, $report['levy_paid'], 0.01);
    }

    public function test_an_employer_under_the_threshold_is_exempt(): void
    {
        $this->paid(400000);

        $report = $this->report();

        // Only judgeable over the whole year, which is why a monthly EMP201 cannot answer it.
        $this->assertTrue($report['exempt']);
        $this->assertEqualsWithDelta(0, $report['levy_paid'], 0.01);
    }

    // ── The grant ────────────────────────────────────────────────────────────

    public function test_the_mandatory_grant_is_a_fifth_of_the_levy(): void
    {
        $this->paid(600000);

        $this->assertEqualsWithDelta(1200, $this->report()['mandatory_grant'], 0.01);
    }

    public function test_an_unsubmitted_plan_is_reported_as_money_left_behind(): void
    {
        $this->paid(600000);

        $report = $this->report();

        $this->assertFalse($report['submitted']);
        $this->assertNotEmpty($report['findings']);
        $this->assertStringContainsString('WSP', $report['findings'][0]);
    }

    public function test_a_submitted_plan_with_the_grant_received_leaves_nothing_outstanding(): void
    {
        $this->paid(600000);
        DB::table('lms_wsp_submissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'year' => 2027,
            'seta' => 'MERSETA', 'submitted_at' => now(), 'status' => 'paid',
            'grant_expected' => 1200, 'grant_received' => 1200,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->report();

        $this->assertTrue($report['submitted']);
        $this->assertEqualsWithDelta(1200, $report['grant_claimed'], 0.01);
        $this->assertEqualsWithDelta(0, $report['grant_outstanding'], 0.01);
        $this->assertSame([], $report['findings']);
    }

    public function test_a_grant_paid_short_is_flagged_as_still_outstanding(): void
    {
        $this->paid(600000);
        DB::table('lms_wsp_submissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'year' => 2027,
            'submitted_at' => now(), 'status' => 'approved',
            'grant_expected' => 1200, 'grant_received' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->report();

        $this->assertEqualsWithDelta(700, $report['grant_outstanding'], 0.01);
        $this->assertStringContainsString('outstanding from the SETA', implode(' ', $report['findings']));
    }

    public function test_an_exempt_employer_paying_the_levy_anyway_is_told(): void
    {
        // Nothing else in the system would notice this, and it is money going out every month.
        $report = app(SkillsLevyService::class)->forYear(2027, $this->companyId);

        $this->assertTrue($report['exempt']);
        $this->assertSame([], $report['findings']);   // exempt and paying nothing is correct
    }
}
