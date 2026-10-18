<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\HR\Payroll\Services\PayrollService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Employment Tax Incentive on the in-house payroll engine.
 *
 * It did not exist. `Emp201Service` carried a literal `$eti = 0.0; // normal engine does not track
 * ETI` and the payslip had nowhere to put one, so every employer running their own payroll on this
 * product paid SARS the full PAYE and never claimed an incentive they were entitled to. Nothing
 * failed and nothing was reported — the money was simply left on the table.
 *
 * Deliberately transactional rather than extending PayrollTestCase: that base class uses
 * RefreshDatabase, which rebuilds the whole schema and would wipe a database other work is using.
 */
class InHouseEtiTest extends TestCase
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
                'eti' => json_encode([
                    'qualify_max' => 7500, 'lower' => 2000, 'upper' => 4500, 'benefit_cap' => 6500,
                    'min_age' => 18, 'max_age' => 29, 'max_months' => 24, 'first_year_months' => 12,
                    'p1' => ['pct' => 0.75, 'flat' => 1500, 'taper' => 0.75],
                    'p2' => ['pct' => 0.375, 'flat' => 750, 'taper' => 0.375],
                ]),
                'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    /** A user with an HR record, which is where the in-house engine reads statutory inputs from. */
    private function hire(array $person = []): array
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'inhouse+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        $personId = DB::table('hr_people')->insertGetId(array_merge([
            'user_id'             => $user->id,
            'first_name'          => 'Sipho',
            'surname'             => 'Ndlovu',
            // A Unix timestamp, because that is what this legacy column holds. Twenty-two years old
            // on the period below.
            'date_of_birth'       => \Illuminate\Support\Carbon::parse('2004-01-01')->timestamp,
            'employment_category' => EmploymentCategory::INTERN,
            'eti_opt_in'          => true,
            'company_id'          => $this->companyId,
            'second_manager_id'   => 0,
            'eligible_to_drive'   => 0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $person));

        return [$user, $personId];
    }

    private function payslipFor(User $user, float $gross): Payslip
    {
        $run = PayrollRun::create([
            'uuid' => (string) Str::uuid(), 'name' => 'March 2026',
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'status' => 'draft', 'employee_count' => 1, 'created_by' => $user->id,
            'company_id' => $this->companyId,
        ]);

        return Payslip::create([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $run->id, 'employee_id' => $user->id,
            'status' => 'draft', 'basic_salary' => $gross, 'gross_pay' => $gross,
            'total_deductions' => 0, 'net_pay' => $gross, 'paye' => 0, 'uif' => 0, 'pension' => 0,
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'company_id' => $this->companyId,
        ]);
    }

    public function test_the_in_house_engine_now_earns_the_incentive(): void
    {
        [$user] = $this->hire();
        $payslip = $this->payslipFor($user, 3000);

        $eti = app(PayrollService::class)->calculateEti($payslip, 3000);

        // R3 000 a month sits in the flat band: the employer may claim R1 500 in the first year.
        $this->assertSame(1500.0, $eti, 'The engine used to return a hard-coded zero.');
    }

    public function test_the_incentive_never_touches_the_employee(): void
    {
        [$user] = $this->hire();
        $payslip = $this->payslipFor($user, 3000);

        $before = (float) $payslip->net_pay;
        app(PayrollService::class)->calculateEti($payslip, 3000);

        // The incentive belongs to the employer and reduces what they owe SARS. An employee whose
        // net pay rose because their employer qualified would be a serious error, not a bonus.
        $this->assertSame($before, (float) $payslip->fresh()->net_pay);
        $this->assertSame(0.0, (float) $payslip->fresh()->total_deductions);
    }

    public function test_it_is_recorded_in_the_same_ledger_as_the_bureau(): void
    {
        [$user, $personId] = $this->hire();
        $payslip = $this->payslipFor($user, 3000);

        app(PayrollService::class)->calculateEti($payslip, 3000);

        $claim = EtiClaim::query()->forEmployee(EtiClaim::HR, $personId)->first();

        $this->assertNotNull($claim);
        $this->assertTrue($claim->eligible);
        $this->assertSame(1, $claim->claim_month);
        $this->assertSame('2026-03', $claim->period_key);
    }

    public function test_a_second_calculation_of_the_same_month_does_not_consume_another(): void
    {
        [$user, $personId] = $this->hire();
        $payslip = $this->payslipFor($user, 3000);

        app(PayrollService::class)->calculateEti($payslip, 3000);
        app(PayrollService::class)->calculateEti($payslip, 3000);
        app(PayrollService::class)->calculateEti($payslip, 3000);

        // Recalculating a run is routine. It must correct the claim, not spend the entitlement.
        $this->assertSame(1, app(EtiClaimService::class)->claimedMonths(EtiClaim::HR, $personId));
    }

    public function test_somebody_not_opted_in_claims_nothing_and_the_reason_is_kept(): void
    {
        [$user, $personId] = $this->hire(['eti_opt_in' => false, 'employment_category' => EmploymentCategory::PERMANENT]);
        $payslip = $this->payslipFor($user, 3000);

        $this->assertSame(0.0, app(PayrollService::class)->calculateEti($payslip, 3000));

        $claim = EtiClaim::query()->forEmployee(EtiClaim::HR, $personId)->first();
        $this->assertFalse($claim->eligible);
        $this->assertSame('Not opted in to the incentive', $claim->reason);
    }

    public function test_somebody_too_well_paid_claims_nothing_and_the_reason_says_so(): void
    {
        [$user, $personId] = $this->hire();
        $payslip = $this->payslipFor($user, 40000);

        $this->assertSame(0.0, app(PayrollService::class)->calculateEti($payslip, 40000));
        $this->assertStringContainsString(
            'outside the qualifying bands',
            EtiClaim::query()->forEmployee(EtiClaim::HR, $personId)->first()->reason,
        );
    }

    public function test_the_entitlement_follows_the_person_across_engines(): void
    {
        [$user, $personId] = $this->hire();

        // Twenty-four months already claimed — whichever engine claimed them.
        for ($i = 1; $i <= 24; $i++) {
            app(EtiClaimService::class)->record(
                EtiClaim::HR, $personId,
                \Illuminate\Support\Carbon::parse('2023-01-01')->addMonths($i)->format('Y-m'),
                '2023-01-01', '2023-01-31', 2023, 500.0, 3000.0, 22, $i,
            );
        }

        $payslip = $this->payslipFor($user, 3000);

        $this->assertSame(0.0, app(PayrollService::class)->calculateEti($payslip, 3000));
        $this->assertStringContainsString(
            'qualifying months have been claimed',
            EtiClaim::query()->forEmployee(EtiClaim::HR, $personId)->where('period_key', '2026-03')->first()->reason,
        );
    }
}
