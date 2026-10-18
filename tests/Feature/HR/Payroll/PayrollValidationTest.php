<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Services\Bureau\PayrollValidationService;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The checks that run before a payroll is approved.
 *
 * Every one of these otherwise gets discovered by the employee. A missing bank account is found on
 * payday; somebody left on the wrong pay cycle is found when they are not paid at all; a duplicated
 * account number is found by whoever received two salaries.
 */
class PayrollValidationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $agent;
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

        $this->seedTaxYear(2027);

        $this->agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'valid+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->agent);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Checked Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->agent->id, 'created_by' => $this->agent->id,
            'pay_frequency' => 'monthly', 'bank' => 'fnb',
            'bank_account_number' => '62000000001', 'bank_branch_code' => '250655',
        ]);
    }

    private function seedTaxYear(int $year): void
    {
        DB::table('sa_tax_years')->updateOrInsert(
            ['country' => 'ZA', 'tax_year' => $year],
            [
                'starts_on' => ($year - 1) . '-03-01', 'ends_on' => $year . '-02-28',
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

    private function hire(array $attributes = []): BureauEmployee
    {
        return app(BureauEmployeeService::class)->create($this->client, array_merge([
            'first_name' => 'Ayanda', 'last_name' => 'Zwane',
            'start_date' => '2025-01-01', 'basic_salary' => 15000,
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'tax_number' => (string) random_int(1000000000, 9999999999),
            'bank_name' => 'FNB', 'branch_code' => '250655', 'account_number' => '62' . random_int(100000000, 999999999),
        ], $attributes));
    }

    private function processedRun(array $overrides = []): BureauPayRun
    {
        $run = app(BureauPayRunService::class)->create($this->client, array_merge([
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ], $overrides));

        return app(BureauPayRunService::class)->process($run);
    }

    /** @return list<string> the codes reported */
    private function codes(BureauPayRun $run): array
    {
        return array_column(app(PayrollValidationService::class)->validate($run)['findings'], 'code');
    }

    public function test_a_clean_run_reports_nothing(): void
    {
        $this->hire();
        $run = $this->processedRun();

        $report = app(PayrollValidationService::class)->validate($run);

        $this->assertSame(0, $report['blockers']);
        $this->assertSame([], $report['findings'], 'A well-formed run must not cry wolf.');
    }

    public function test_a_missing_bank_account_is_reported_but_does_not_stop_the_payroll(): void
    {
        $this->hire(['account_number' => null, 'branch_code' => null]);
        $run = $this->processedRun();

        $report = app(PayrollValidationService::class)->validate($run);

        $this->assertContains('no_bank_account', array_column($report['findings'], 'code'));
        // They still worked the month. Refusing to pay everybody because one person has no account
        // on file is not a fix.
        $this->assertSame(0, $report['blockers']);
    }

    public function test_a_bank_account_that_cannot_be_right_is_reported(): void
    {
        $this->hire(['branch_code' => '25', 'account_number' => '123']);
        $run = $this->processedRun();

        $this->assertContains('invalid_bank_account', $this->codes($run));
    }

    public function test_a_missing_tax_number_is_reported(): void
    {
        $this->hire(['tax_number' => null]);
        $run = $this->processedRun();

        // No reason to stop paying somebody in March; a real problem for their IRP5 in February,
        // which is exactly why it is raised now rather than found at year end.
        $this->assertContains('no_tax_number', $this->codes($run));
    }

    public function test_somebody_with_no_identity_document_is_reported(): void
    {
        $this->hire(['id_number' => null, 'passport_number' => null]);
        $run = $this->processedRun();

        $this->assertContains('no_identity', $this->codes($run));
    }

    public function test_the_same_person_twice_blocks_the_run(): void
    {
        $id = '8801015800085';
        $this->hire(['id_number' => $id]);
        $this->hire(['first_name' => 'Ayanda', 'last_name' => 'Zwane (duplicate)', 'id_number' => $id]);

        $run = $this->processedRun();
        $report = app(PayrollValidationService::class)->validate($run);

        $this->assertContains('duplicate_employee', array_column($report['findings'], 'code'));
        $this->assertGreaterThan(0, $report['blockers']);
    }

    public function test_two_people_sharing_a_bank_account_is_reported_not_blocked(): void
    {
        $account = '62123456789';
        $this->hire(['account_number' => $account]);
        $this->hire(['first_name' => 'Thabo', 'account_number' => $account]);

        $run = $this->processedRun();
        $report = app(PayrollValidationService::class)->validate($run);

        // Legitimate for a married couple, usually a copied row. Blocking it would stop a real
        // payroll for a reason nobody can fix.
        $this->assertContains('shared_account', array_column($report['findings'], 'code'));
        $this->assertSame(0, $report['blockers']);
    }

    public function test_somebody_who_starts_after_the_period_ends_blocks_the_run(): void
    {
        $employee = $this->hire();
        // Backdated onto a run they do not belong on — the shape a mistyped start date takes.
        $employee->forceFill(['start_date' => '2026-06-01'])->save();

        $run = $this->processedRun();

        $this->assertContains('starts_after_period', $this->codes($run));
    }

    public function test_a_big_swing_in_gross_is_reported(): void
    {
        $employee = $this->hire();

        $february = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'tax_year' => 2026,
        ]);
        $this->seedTaxYear(2026);
        app(BureauPayRunService::class)->process($february);

        // The shape a fat-fingered extra zero takes.
        $employee->forceFill(['basic_salary' => 150000])->save();
        $march = $this->processedRun();

        $this->assertContains('salary_variance', $this->codes($march));
    }

    public function test_claiming_the_incentive_past_its_twenty_four_months_blocks_the_run(): void
    {
        $employee = $this->hire([
            'basic_salary' => 3000,
            'date_of_birth' => '2004-01-01',
            'employment_category' => EmploymentCategory::INTERN,
        ]);

        $claims = app(EtiClaimService::class);
        for ($i = 1; $i <= 24; $i++) {
            $claims->record(
                EtiClaim::BUREAU, (int) $employee->id,
                Carbon::parse('2023-01-01')->addMonths($i)->format('Y-m'),
                '2023-01-01', '2023-01-31', 2023, 1500.0, 3000.0, 22, $i,
            );
        }

        // The engine itself refuses to claim once the ledger is spent, so nothing should be on the
        // payslip — the check exists for the run that got there another way (an import, a fix
        // applied to the ledger afterwards), because SARS recovers an over-claim with interest.
        $run = $this->processedRun();
        $slip = $run->payslips()->first();
        $slip->forceFill(['eti' => 1500])->save();

        $this->assertContains('eti_exhausted', $this->codes($run->fresh()));
    }

    public function test_an_intern_who_is_not_opted_in_is_reported(): void
    {
        $employee = $this->hire(['basic_salary' => 3000, 'date_of_birth' => '2004-01-01']);
        // Set directly rather than through the service, which would opt them in automatically —
        // this models a record that predates that behaviour.
        $employee->forceFill([
            'employment_category' => EmploymentCategory::INTERN,
            'eti_opt_in' => false,
        ])->save();

        $run = $this->processedRun();

        $this->assertContains('category_not_opted_in', $this->codes($run));
    }

    public function test_totals_that_do_not_match_the_payslips_block_the_run(): void
    {
        $this->hire();
        $run = $this->processedRun();

        // What the EMP201 and the payment file are built from. A run whose totals have drifted from
        // its payslips reports one figure to SARS and pays another.
        $run->forceFill(['total_net' => (float) $run->total_net + 5000])->save();

        $this->assertContains('totals_mismatch', $this->codes($run->fresh()));
    }

    public function test_a_run_with_no_payslips_cannot_be_approved(): void
    {
        $run = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]);

        $this->assertContains('no_payslips', $this->codes($run));
    }

    public function test_approval_is_refused_while_a_blocker_stands(): void
    {
        $employee = $this->hire();
        $run = $this->processedRun();
        $employee->forceFill(['start_date' => '2026-06-01'])->save();

        // Approval is where the blockers are refused: it is the last point at which a wrong payment
        // is cheap to fix, and it is the act that carries somebody's name.
        $this->expectException(ValidationException::class);
        app(BureauPayRunService::class)->approve($run->fresh());
    }

    public function test_a_warning_alone_does_not_refuse_the_run(): void
    {
        $this->hire(['tax_number' => null]);
        $run = $this->processedRun();

        // Refusing over a warning teaches people to look for the override, and then the blockers
        // stop working too.
        app(BureauPayRunService::class)->approve($run);
        $finalised = app(BureauPayRunService::class)->finalise($run->fresh());

        $this->assertSame(BureauPayRun::STATUS_FINALISED, $finalised->status);
    }
}
