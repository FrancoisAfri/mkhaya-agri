<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\CoidaRegistration;
use App\Modules\HR\Payroll\Models\CoidaReturn;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Services\CoidaReturnService;
use App\Modules\HR\Payroll\Support\LabourRates;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The Return of Earnings, and overtime at the statutory multipliers.
 *
 * Neither existed. COIDA had no presence in payroll at all, and overtime could be detected as
 * underpaid but never calculated correctly — it arrived as an amount somebody typed in.
 */
class CoidaAndOvertimeTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $officer;
    private BureauCompany $client;

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
                'eti' => json_encode(['qualify_max' => 7500, 'lower' => 2000, 'upper' => 4500,
                    'benefit_cap' => 6500, 'min_age' => 18, 'max_age' => 29, 'max_months' => 24,
                    'first_year_months' => 12,
                    'p1' => ['pct' => 0.75, 'flat' => 1500, 'taper' => 0.75],
                    'p2' => ['pct' => 0.375, 'flat' => 750, 'taper' => 0.375]]),
                'lump_sum_severance' => json_encode([[0, 0, 0], [550000, 0.18, 0]]),
                'lump_sum_withdrawal' => json_encode([[0, 0, 0], [27500, 0.18, 0]]),
                'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        $this->officer = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'coida+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->officer);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'COIDA Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->officer->id, 'created_by' => $this->officer->id,
            'pay_frequency' => 'monthly', 'bank' => 'fnb',
            'bank_account_number' => '62000000001', 'bank_branch_code' => '250655',
        ]);
    }

    private function hire(float $salary, string $first = 'Thabo'): void
    {
        app(BureauEmployeeService::class)->create($this->client, [
            'first_name' => $first, 'last_name' => 'Mokoena',
            'start_date' => '2025-01-01', 'basic_salary' => $salary,
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'tax_number' => (string) random_int(1000000000, 9999999999),
            'bank_name' => 'FNB', 'branch_code' => '250655',
            'account_number' => '62' . random_int(100000000, 999999999),
        ]);
    }

    /** A finalised month, so the return has settled figures to read. */
    private function finalisedMonth(string $start, string $end): void
    {
        $runs = app(BureauPayRunService::class);
        $run = $runs->create($this->client, ['period_start' => $start, 'period_end' => $end, 'tax_year' => 2027]);
        $runs->process($run);
        $runs->approve($run->fresh());
        $runs->finalise($run->fresh());
    }

    // ── The cap is the calculation ───────────────────────────────────────────

    public function test_each_employee_is_capped_at_the_ceiling_not_the_total(): void
    {
        $ceiling = app(LabourRates::class)->coidaCeiling('2027-02-28');

        // Two people well over the ceiling. Capping the total instead of each person — the obvious
        // mistake — would assess one ceiling instead of two and understate the levy by half.
        $this->hire($ceiling / 6, 'Rich');       // ~2 months puts them over
        $this->hire($ceiling / 6, 'Alsorich');

        $this->finalisedMonth('2026-03-01', '2026-03-31');
        $this->finalisedMonth('2026-04-01', '2026-04-30');
        $this->finalisedMonth('2026-05-01', '2026-05-31');
        $this->finalisedMonth('2026-06-01', '2026-06-30');
        $this->finalisedMonth('2026-07-01', '2026-07-31');
        $this->finalisedMonth('2026-08-01', '2026-08-31');
        $this->finalisedMonth('2026-09-01', '2026-09-30');

        $return = app(CoidaReturnService::class)->prepare($this->client, 2027);

        $this->assertSame(2, $return->employee_count);
        foreach ($return->employees as $row) {
            $this->assertLessThanOrEqual($ceiling + 0.01, (float) $row['leviable']);
        }
        $this->assertLessThanOrEqual($ceiling * 2 + 0.01, (float) $return->total_leviable_earnings);
    }

    public function test_somebody_under_the_ceiling_is_not_capped(): void
    {
        $this->hire(15000);
        $this->finalisedMonth('2026-03-01', '2026-03-31');

        $return = app(CoidaReturnService::class)->prepare($this->client, 2027);

        $row = $return->employees[0];
        $this->assertSame((float) $row['actual'], (float) $row['leviable']);
        $this->assertFalse($row['capped']);
    }

    public function test_the_assessment_is_the_rate_per_hundred_of_leviable_earnings(): void
    {
        CoidaRegistration::create([
            'bureau_company_id' => $this->client->id, 'company_id' => $this->companyId,
            'registration_number' => '990000000', 'assessment_rate_per_100' => 1.5,
        ]);

        $this->hire(10000);
        $this->finalisedMonth('2026-03-01', '2026-03-31');

        $return = app(CoidaReturnService::class)->prepare($this->client, 2027);

        // The Fund quotes its rate per R100 of leviable earnings.
        $expected = round((float) $return->total_leviable_earnings / 100 * 1.5, 2);
        $this->assertSame($expected, (float) $return->assessment);
    }

    public function test_only_settled_runs_are_counted(): void
    {
        $this->hire(20000);

        // Calculated but never approved or finalised — a figure somebody is still working on.
        $runs = app(BureauPayRunService::class);
        $runs->process($runs->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]));

        $return = app(CoidaReturnService::class)->prepare($this->client, 2027);

        // Nothing approved means nothing to declare, rather than a draft figure in a statutory return.
        $this->assertSame(0, $return->employee_count);
    }

    public function test_a_draft_return_can_be_rebuilt_as_the_year_moves(): void
    {
        $this->hire(20000);
        $this->finalisedMonth('2026-03-01', '2026-03-31');

        $service = app(CoidaReturnService::class);
        $first = $service->prepare($this->client, 2027);

        $this->finalisedMonth('2026-04-01', '2026-04-30');
        $second = $service->prepare($this->client, 2027);

        $this->assertSame($first->id, $second->id, 'Rebuilding a draft must not create a second return.');
        $this->assertGreaterThan((float) $first->total_actual_earnings, (float) $second->total_actual_earnings);
    }

    public function test_a_submitted_return_is_never_overwritten(): void
    {
        $this->hire(20000);
        $this->finalisedMonth('2026-03-01', '2026-03-31');

        $service = app(CoidaReturnService::class);
        $service->markSubmitted($service->prepare($this->client, 2027));

        // That one is a record of what was filed.
        $this->expectException(ValidationException::class);
        $service->prepare($this->client, 2027);
    }

    public function test_a_year_with_no_ceiling_refuses_rather_than_assessing_zero(): void
    {
        $this->hire(20000);
        $this->finalisedMonth('2026-03-01', '2026-03-31');

        // 2010 predates the rates table entirely.
        $this->expectException(ValidationException::class);
        app(CoidaReturnService::class)->prepare($this->client, 2010);
    }

    public function test_the_return_exports_with_its_per_employee_breakdown(): void
    {
        $this->hire(20000, 'Naledi');
        $this->finalisedMonth('2026-03-01', '2026-03-31');

        $csv = app(CoidaReturnService::class)->toCsv(
            app(CoidaReturnService::class)->prepare($this->client, 2027)
        );

        $this->assertStringContainsString('Leviable earnings', $csv);
        $this->assertStringContainsString('Naledi', $csv);
        $this->assertStringContainsString('Ceiling applied', $csv);
    }

    public function test_the_assessment_year_reads_march_to_february(): void
    {
        [$from, $to] = app(CoidaReturnService::class)->yearBounds(2027);

        $this->assertSame('2026-03-01', $from->toDateString());
        $this->assertSame('2027-02-28', $to->toDateString());
    }
}
