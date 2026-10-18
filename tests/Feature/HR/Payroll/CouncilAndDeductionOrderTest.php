<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Services\BargainingCouncilService;
use App\Modules\HR\Payroll\Services\DeductionPriorityService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bargaining council levies, and what happens when the deductions do not fit.
 *
 * Neither existed. The only thing named after bargaining councils was a BCEA overtime check, so an
 * employer in a council sector added a manual line every month. And deductions were summed and
 * subtracted in whatever order the rows came back, so a month with a garnishee and a maintenance
 * order could take somebody to zero with the wrong creditor paid first.
 */
class CouncilAndDeductionOrderTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private int $authorId;

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

        $this->authorId = (int) User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'bc+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ])->id;
    }

    private function payRun(): PayrollRun
    {
        return PayrollRun::create([
            'uuid' => (string) Str::uuid(), 'name' => 'September', 'company_id' => $this->companyId,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'status' => 'draft', 'created_by' => $this->authorId,
        ]);
    }

    private function payslip(PayrollRun $run, float $basic = 20000): Payslip
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'emp' . Str::random(6) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        return Payslip::create([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $run->id, 'employee_id' => $user->id,
            'status' => 'draft', 'basic_salary' => $basic, 'gross_pay' => $basic,
            'total_deductions' => 0, 'net_pay' => $basic,
            'period_start' => $run->period_start, 'period_end' => $run->period_end,
            'company_id' => $this->companyId,
        ]);
    }

    private function council(array $rate = []): int
    {
        $councilId = DB::table('bargaining_councils')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'MEIBC', 'sector' => 'Metal and engineering', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('bargaining_council_rates')->insert(array_merge([
            'company_id' => $this->companyId, 'council_id' => $councilId,
            'effective_from' => '2026-01-01', 'basis' => 'fixed',
            'employee_amount' => 25, 'employer_amount' => 25,
            'employee_percent' => 0, 'employer_percent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $rate));

        return $councilId;
    }

    private function cover(int $councilId, int $employeeId, array $dates = []): void
    {
        DB::table('bargaining_council_members')->insert(array_merge([
            'company_id' => $this->companyId, 'council_id' => $councilId,
            'employee_id' => $employeeId, 'created_at' => now(), 'updated_at' => now(),
        ], $dates));
    }

    private function lines(int $payslipId): array
    {
        return DB::table('payroll_earnings_lines')->where('payslip_id', $payslipId)
            ->get()->keyBy('line_type')->map(fn ($r) => (float) $r->amount)->all();
    }

    // ── The levy ─────────────────────────────────────────────────────────────

    public function test_a_fixed_levy_is_deducted_from_a_covered_employee(): void
    {
        $run = $this->payRun();
        $payslip = $this->payslip($run);
        $this->cover($this->council(), $payslip->employee_id);

        $result = app(BargainingCouncilService::class)->applyToRun($run->fresh());

        $this->assertSame(1, $result['employees']);
        $lines = $this->lines($payslip->id);
        $this->assertEqualsWithDelta(25, $lines['other_deduction'], 0.01);
        $this->assertEqualsWithDelta(25, $lines['employer_council'], 0.01);
    }

    public function test_an_uncovered_employee_in_the_same_run_is_not_levied(): void
    {
        $run = $this->payRun();
        $covered = $this->payslip($run);
        $uncovered = $this->payslip($run);
        $this->cover($this->council(), $covered->employee_id);

        app(BargainingCouncilService::class)->applyToRun($run->fresh());

        // One employer routinely has covered and uncovered staff; levying everybody would be as
        // wrong as levying nobody.
        $this->assertSame([], $this->lines($uncovered->id));
    }

    public function test_a_percentage_levy_is_capped_at_the_councils_ceiling(): void
    {
        $run = $this->payRun();
        $payslip = $this->payslip($run, 50000);
        $this->cover($this->council([
            'basis' => 'percentage', 'employee_percent' => 1, 'employer_percent' => 1,
            'employee_ceiling' => 120, 'employer_ceiling' => 120,
        ]), $payslip->employee_id);

        app(BargainingCouncilService::class)->applyToRun($run->fresh());

        // 1% of 50 000 is 500; without the ceiling a high earner is over-deducted every month.
        $this->assertEqualsWithDelta(120, $this->lines($payslip->id)['other_deduction'], 0.01);
    }

    public function test_processing_a_run_twice_does_not_levy_twice(): void
    {
        $run = $this->payRun();
        $payslip = $this->payslip($run);
        $this->cover($this->council(), $payslip->employee_id);

        app(BargainingCouncilService::class)->applyToRun($run->fresh());
        app(BargainingCouncilService::class)->applyToRun($run->fresh());

        $count = DB::table('payroll_earnings_lines')->where('payslip_id', $payslip->id)
            ->where('source', 'bargaining_council')->count();
        $this->assertSame(2, $count);   // one employee line, one employer line
    }

    public function test_somebody_who_left_the_council_before_the_period_is_not_levied(): void
    {
        $run = $this->payRun();
        $payslip = $this->payslip($run);
        $this->cover($this->council(), $payslip->employee_id, ['left_on' => '2026-06-30']);

        app(BargainingCouncilService::class)->applyToRun($run->fresh());

        $this->assertSame([], $this->lines($payslip->id));
    }

    public function test_the_rate_in_force_on_the_period_is_the_one_used(): void
    {
        $councilId = $this->council(['employee_amount' => 25, 'effective_to' => '2026-08-31']);
        DB::table('bargaining_council_rates')->insert([
            'company_id' => $this->companyId, 'council_id' => $councilId,
            'effective_from' => '2026-09-01', 'basis' => 'fixed',
            'employee_amount' => 40, 'employer_amount' => 40,
            'employee_percent' => 0, 'employer_percent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = $this->payRun();
        $payslip = $this->payslip($run);
        $this->cover($councilId, $payslip->employee_id);

        app(BargainingCouncilService::class)->applyToRun($run->fresh());

        // A council gazettes new rates; an old payslip keeps the old one.
        $this->assertEqualsWithDelta(40, $this->lines($payslip->id)['other_deduction'], 0.01);
    }

    // ── When the deductions do not fit ───────────────────────────────────────

    private function deduction(Payslip $payslip, string $type, string $description, float $amount): void
    {
        DB::table('payroll_earnings_lines')->insert([
            'payslip_id' => $payslip->id, 'line_type' => $type, 'description' => $description,
            'amount' => $amount, 'quantity' => 1, 'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_maintenance_order_outranks_a_garnishee(): void
    {
        $service = app(DeductionPriorityService::class);

        // This is why they are separate kinds rather than one "court order".
        $this->assertLessThan(
            $service->priorityFor('other_deduction', 'Garnishee order — ABC Finance'),
            $service->priorityFor('other_deduction', 'Maintenance order — Family Court'),
        );
    }

    public function test_statutory_deductions_come_before_any_creditor(): void
    {
        $service = app(DeductionPriorityService::class);

        $this->assertLessThan($service->priorityFor('other_deduction', 'Maintenance order'), $service->priorityFor('paye'));
        $this->assertLessThan($service->priorityFor('staff_loan'), $service->priorityFor('uif'));
    }

    public function test_deductions_that_fit_are_all_taken(): void
    {
        $payslip = $this->payslip($this->payRun(), 20000);
        $this->deduction($payslip, 'paye', 'PAYE', 3000);
        $this->deduction($payslip, 'medical_aid', 'Discovery', 1500);

        $result = app(DeductionPriorityService::class)->resolve($payslip, 20000);

        $this->assertEqualsWithDelta(4500, $result['total'], 0.01);
        $this->assertSame([], $result['trimmed']);
    }

    public function test_the_lowest_priority_deduction_is_trimmed_rather_than_the_employee_zeroed(): void
    {
        $payslip = $this->payslip($this->payRun(), 10000);
        $this->deduction($payslip, 'paye', 'PAYE', 1500);
        $this->deduction($payslip, 'other_deduction', 'Maintenance order', 4000);
        $this->deduction($payslip, 'other_deduction', 'Staff loan repayment', 5000);

        $result = app(DeductionPriorityService::class)->resolve($payslip, 10000);

        // The floor is 25% of gross. PAYE is taken regardless; maintenance is paid in full; the
        // loan absorbs the shortfall rather than the employee going home with nothing.
        $this->assertSame('Staff loan repayment', $result['trimmed'][0]['description']);
        $this->assertGreaterThan(0, $result['shortfall']);
        $this->assertEqualsWithDelta(2500, $result['floor'], 0.01);
    }

    public function test_the_shortfall_is_reported_so_the_creditor_can_be_told(): void
    {
        $payslip = $this->payslip($this->payRun(), 8000);
        $this->deduction($payslip, 'other_deduction', 'Garnishee order', 7000);

        $result = app(DeductionPriorityService::class)->resolve($payslip, 8000);

        // Better a creditor told than an employee discovering it on payday.
        $this->assertNotEmpty($result['trimmed']);
        $this->assertEqualsWithDelta(1000, $result['trimmed'][0]['short'], 0.01);
    }

    public function test_an_employer_contribution_is_never_taken_off_the_employee(): void
    {
        $payslip = $this->payslip($this->payRun(), 10000);
        $this->deduction($payslip, 'employer_medical', 'Employer medical', 2000);

        $this->assertEqualsWithDelta(0, app(DeductionPriorityService::class)->resolve($payslip, 10000)['total'], 0.01);
    }
}
