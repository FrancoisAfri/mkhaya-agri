<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Overtime on the bureau side, where there is no attendance to read.
 *
 * A bureau's clients do not clock into this system. The officer captures hours and the engine
 * prices them against each employee's own rate — rather than asking somebody to do the BCEA
 * arithmetic per person on a spreadsheet, which is where the mistakes are.
 */
class BureauOvertimeTest extends TestCase
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
                'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        $this->officer = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'bot+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->officer);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Overtime Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->officer->id, 'created_by' => $this->officer->id,
            'pay_frequency' => 'monthly', 'bank' => 'fnb',
            'bank_account_number' => '62000000001', 'bank_branch_code' => '250655',
        ]);
    }

    /** R16 000 over 160 standard hours is exactly R100/hr, which keeps the sums readable. */
    private function hire(float $salary = 16000, string $first = 'Sipho', array $extra = []): BureauEmployee
    {
        return app(BureauEmployeeService::class)->create($this->client, array_merge([
            'first_name' => $first, 'last_name' => 'Dube',
            'start_date' => '2025-01-01', 'basic_salary' => $salary,
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'tax_number' => (string) random_int(1000000000, 9999999999),
            'bank_name' => 'FNB', 'branch_code' => '250655',
            'account_number' => '62' . random_int(100000000, 999999999),
        ], $extra));
    }

    private function runs(): BureauPayRunService
    {
        return app(BureauPayRunService::class);
    }

    private function makeRun(): BureauPayRun
    {
        return $this->runs()->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]);
    }

    public function test_overtime_hours_are_priced_at_one_and_a_half_times(): void
    {
        $employee = $this->hire();
        $input = $this->runs()->addOvertimeHours($this->makeRun(), $employee, 'overtime', 3);

        // R100/hr × 1.5 × 3 hrs = R450. The officer entered "3", not "450".
        $this->assertSame(450.0, (float) $input->amount);
        $this->assertSame(100.0, (float) $input->rate_applied);
        $this->assertSame(1.5, (float) $input->multiplier);
    }

    public function test_sunday_and_public_holiday_hours_are_priced_at_double(): void
    {
        $run = $this->makeRun();
        $employee = $this->hire();

        $this->assertSame(800.0, (float) $this->runs()->addOvertimeHours($run, $employee, 'sunday_time', 4)->amount);
        $this->assertSame(600.0, (float) $this->runs()->addOvertimeHours($run, $employee, 'public_holiday_time', 3)->amount);
    }

    public function test_the_same_hours_produce_different_amounts_per_employee(): void
    {
        $run = $this->makeRun();
        $low = $this->hire(16000, 'Low');      // R100/hr
        $high = $this->hire(32000, 'High');    // R200/hr

        $result = $this->runs()->addOvertimeForMany($run, [$low->id, $high->id], 'overtime', 2);

        // This is the whole reason to do it here rather than on a spreadsheet.
        $this->assertSame(2, $result['applied']);
        $this->assertSame(900.0, $result['total']);   // 300 + 600
    }

    public function test_capturing_for_many_is_the_common_bureau_case(): void
    {
        $run = $this->makeRun();
        $ids = collect(['A', 'B', 'C'])->map(fn ($n) => $this->hire(16000, $n)->id)->all();

        // "These three worked four hours on Saturday" is how the instruction arrives.
        $result = $this->runs()->addOvertimeForMany($run, $ids, 'overtime', 4);

        $this->assertSame(3, $result['applied']);
        $this->assertSame(1800.0, $result['total']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_somebody_with_no_salary_is_skipped_and_named(): void
    {
        $run = $this->makeRun();
        $ok = $this->hire(16000, 'Paid');
        $noSalary = $this->hire(0, 'Unpaid');

        $result = $this->runs()->addOvertimeForMany($run, [$ok->id, $noSalary->id], 'overtime', 2);

        // A zero line on a payslip raises a question nobody can answer later.
        $this->assertSame(1, $result['applied']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Unpaid', $result['skipped'][0]);
    }

    public function test_recapturing_corrects_rather_than_pays_twice(): void
    {
        $run = $this->makeRun();
        $employee = $this->hire();

        $this->runs()->addOvertimeHours($run, $employee, 'overtime', 5);
        $this->runs()->addOvertimeHours($run, $employee, 'overtime', 3);

        $inputs = $run->inputs()->where('kind', 'overtime')->get();
        $this->assertCount(1, $inputs, 'A corrected figure must replace the first, not add to it.');
        $this->assertSame(450.0, (float) $inputs->first()->amount);
    }

    public function test_the_three_categories_sit_side_by_side(): void
    {
        $run = $this->makeRun();
        $employee = $this->hire();

        $this->runs()->addOvertimeHours($run, $employee, 'overtime', 2);
        $this->runs()->addOvertimeHours($run, $employee, 'sunday_time', 2);

        // Different kinds are different lines; only the same kind replaces.
        $this->assertSame(2, $run->inputs()->count());
    }

    public function test_a_weekly_employee_is_restated_before_being_priced(): void
    {
        $run = $this->makeRun();
        // R4 000 a week is about R17 333 a month, not R4 000 — pricing the weekly figure directly
        // would value an hour at a quarter of its worth.
        $employee = $this->hire(4000, 'Weekly', ['pay_frequency' => 'weekly']);

        $input = $this->runs()->addOvertimeHours($run, $employee, 'overtime', 1);

        $this->assertGreaterThan(100.0, (float) $input->rate_applied);
    }

    public function test_capturing_overtime_puts_the_person_on_the_run(): void
    {
        // A bonus run starts empty; attaching pay to somebody is saying they are on it.
        $run = $this->runs()->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'tax_year' => 2027, 'run_type' => 'bonus',
        ]);
        $employee = $this->hire();

        $this->runs()->addOvertimeHours($run, $employee, 'overtime', 2);

        $this->assertSame(1, $run->roster()->count());
    }

    public function test_it_reaches_the_payslip(): void
    {
        $run = $this->makeRun();
        $employee = $this->hire();

        $this->runs()->addOvertimeHours($run, $employee, 'overtime', 4);
        $processed = $this->runs()->process($run);

        // R16 000 basic + R600 overtime.
        $this->assertSame(16600.0, round((float) $processed->payslips()->first()->gross, 2));
    }

    public function test_hours_must_be_positive(): void
    {
        $this->expectException(ValidationException::class);
        $this->runs()->addOvertimeHours($this->makeRun(), $this->hire(), 'overtime', 0);
    }

    public function test_a_locked_run_refuses_new_overtime(): void
    {
        $employee = $this->hire();
        $run = $this->runs()->process($this->makeRun());
        $this->runs()->approve($run);

        $this->expectException(ValidationException::class);
        $this->runs()->addOvertimeHours($run->fresh(), $employee, 'overtime', 2);
    }

    public function test_another_clients_employee_is_refused(): void
    {
        $other = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Other ' . Str::random(4),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->officer->id, 'created_by' => $this->officer->id,
            'pay_frequency' => 'monthly',
        ]);
        $theirs = app(BureauEmployeeService::class)->create($other, [
            'first_name' => 'Not', 'last_name' => 'Ours', 'start_date' => '2025-01-01', 'basic_salary' => 10000,
        ]);

        $this->expectException(ValidationException::class);
        $this->runs()->addOvertimeHours($this->makeRun(), $theirs, 'overtime', 2);
    }
}
