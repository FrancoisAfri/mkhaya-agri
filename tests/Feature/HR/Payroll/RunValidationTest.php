<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\RunValidationService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What is wrong with a run, before anybody releases it.
 *
 * The bureau has had this since PayrollValidationService was written. The in-house payroll never
 * did: an employer could lock, submit and release a run with no check at all beyond the anomaly
 * scan, which looks for outliers rather than for errors. Releasing is the irreversible step — it
 * locks the payslips, emails them, posts the journal and charges the budget — so a blocker found
 * afterwards is found by the employee.
 */
class RunValidationTest extends PayrollTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (int) (DB::table('companies')->min('id')
            ?? Company::create(['name' => 'Validation Test Co'])->id);

        DB::table('users')->where('id', $this->hrManager->id)->update(['company_id' => $this->companyId]);
        $this->hrManager->refresh();
        $this->actingAs($this->hrManager);

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        // The engine needs a tax year for the period under test; these tests are about the checks,
        // not the brackets.
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

    private function payrollRun(array $attributes = []): PayrollRun
    {
        return PayrollRun::create(array_merge([
            'company_id'   => $this->companyId,
            'name'         => 'September 2026',
            'ref_number'   => 'PR-' . Str::random(6),
            'period_start' => '2026-09-01',
            'period_end'   => '2026-09-30',
            'frequency'    => 'monthly',
            'status'       => PayrollRunStatus::Review->value,
            'created_by'   => $this->hrManager->id,
            'total_gross'  => 0, 'total_deductions' => 0, 'total_net' => 0, 'employee_count' => 0,
        ], $attributes));
    }

    /** An employee on a run, with the payslip figures the checks read. */
    private function payslipOn(PayrollRun $run, array $payslip = [], array $person = []): int
    {
        $user = User::factory()->create(['status' => 1]);

        DB::table('hr_people')->insert(array_merge([
            'user_id' => $user->id, 'first_name' => 'Thabo', 'surname' => 'Mokoena',
            'employee_number' => 'E' . $user->id, 'id_number' => '9001015800083',
            'income_tax_number' => '1234567890', 'account_number' => '620' . $user->id,
            'monthly_package' => 30000, 'created_at' => now(), 'updated_at' => now(),
        ], $person));

        DB::table('payslips')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'payroll_run_id' => $run->id, 'employee_id' => $user->id,
            'status' => 'draft', 'basic_salary' => 30000, 'gross_pay' => 30000,
            'total_deductions' => 4500, 'net_pay' => 25500, 'paye' => 4500,
            'uif' => 177.12, 'pension' => 0, 'eti' => 0,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'created_at' => now(), 'updated_at' => now(),
        ], $payslip));

        return (int) $user->id;
    }

    /** Bring the run's stored totals in step with its payslips, as calculating would. */
    private function reconcile(PayrollRun $run): PayrollRun
    {
        $sums = DB::table('payslips')->where('payroll_run_id', $run->id)->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(total_deductions),0) d, COALESCE(SUM(net_pay),0) n, COUNT(*) c')
            ->first();

        $run->update([
            'total_gross' => $sums->g, 'total_deductions' => $sums->d,
            'total_net' => $sums->n, 'employee_count' => $sums->c,
        ]);

        return $run->fresh();
    }

    private function validator(): RunValidationService
    {
        return app(RunValidationService::class);
    }

    private function codes(PayrollRun $run): array
    {
        return array_column($this->validator()->validate($run)['findings'], 'code');
    }

    // ── A clean run says so ──────────────────────────────────────────────────

    public function test_a_correct_run_reports_nothing(): void
    {
        $run = $this->payrollRun();
        $this->payslipOn($run);
        $run = $this->reconcile($run);

        $report = $this->validator()->validate($run);

        $this->assertSame(0, $report['blockers']);
        $this->assertSame(0, $report['warnings']);
        $this->assertSame(1, $report['checked']);
    }

    // ── Blockers: things that would produce a wrong payment ──────────────────

    public function test_an_empty_run_is_a_blocker(): void
    {
        $this->assertContains('no_payslips', $this->codes($this->payrollRun()));
    }

    public function test_totals_that_disagree_with_the_payslips_are_a_blocker(): void
    {
        // The totals are what the EMP201, the bank file and the GL posting are built from.
        $run = $this->payrollRun();
        $this->payslipOn($run);
        $run = $this->reconcile($run);
        $run->update(['total_net' => 999999]);

        $this->assertContains('totals_mismatch', $this->codes($run->fresh()));
    }

    public function test_a_negative_net_is_a_blocker(): void
    {
        $run = $this->payrollRun();
        $this->payslipOn($run, ['net_pay' => -120.00]);
        $run = $this->reconcile($run);

        $this->assertContains('negative_net', $this->codes($run));
    }

    public function test_a_second_run_over_the_same_days_is_a_blocker(): void
    {
        // The payslip overlap constraint refuses the second payment at the row level, but it fires
        // during a release already under way. This says so beforehand, while somebody can act.
        $first = $this->payrollRun(['name' => 'September (first)']);
        $this->payslipOn($first);
        $this->reconcile($first);

        $second = $this->payrollRun(['name' => 'September (again)']);
        $this->payslipOn($second);
        $second = $this->reconcile($second);

        $this->assertContains('duplicate_run', $this->codes($second));
    }

    public function test_somebody_on_another_cycle_is_a_blocker(): void
    {
        $run = $this->payrollRun();
        $this->payslipOn($run, person: ['pay_frequency' => 'weekly']);
        $run = $this->reconcile($run);

        $this->assertContains('wrong_frequency', $this->codes($run));
    }

    public function test_an_employee_who_left_before_the_period_is_a_blocker(): void
    {
        $run = $this->payrollRun();
        // Stored as a Unix timestamp in a bigint column, which is how this table keeps some of
        // its dates — and the shape that made Carbon::parse() throw in the service.
        $this->payslipOn($run, person: ['date_left' => strtotime('2026-06-30')]);
        $run = $this->reconcile($run);

        $this->assertContains('left_before_period', $this->codes($run));
    }

    // ── Warnings: real problems, none a reason to stop paying ────────────────

    public function test_a_missing_bank_account_is_a_warning_not_a_blocker(): void
    {
        $run = $this->payrollRun();
        $this->payslipOn($run, person: ['account_number' => null]);
        $run = $this->reconcile($run);

        $report = $this->validator()->validate($run);

        $this->assertSame(0, $report['blockers']);
        $this->assertContains('no_bank_account', array_column($report['findings'], 'code'));
    }

    public function test_a_missing_tax_number_does_not_stop_a_release(): void
    {
        // A real problem for February's IRP5 and no reason to stop paying somebody in March.
        // Refusing over one teaches people to hunt for the override until blockers stop working.
        $run = $this->payrollRun();
        $this->payslipOn($run, person: ['income_tax_number' => null]);
        $run = $this->reconcile($run);

        $report = $this->validator()->validate($run);

        $this->assertSame(0, $report['blockers']);
        $this->assertContains('no_tax_number', array_column($report['findings'], 'code'));
    }

    public function test_two_people_paid_into_one_account_is_a_warning(): void
    {
        // Sometimes a couple, sometimes a fraud, and the only way to tell is for somebody to look.
        $run = $this->payrollRun();
        $this->payslipOn($run, person: ['account_number' => '6201111111']);
        $this->payslipOn($run, person: ['account_number' => '6201111111']);
        $run = $this->reconcile($run);

        $this->assertContains('shared_account', $this->codes($run));
    }

    // ── The release gate ─────────────────────────────────────────────────────

    public function test_a_run_with_a_blocker_cannot_be_released(): void
    {
        $run = $this->payrollRun();
        $this->payslipOn($run, ['net_pay' => -50.00]);
        $run = $this->reconcile($run);

        $this->post(route('payroll.runs.release', $run->uuid))
            ->assertSessionHasErrors('release');

        $this->assertNotSame(PayrollRunStatus::Released->value, $run->fresh()->status->value);
    }

    public function test_warnings_alone_do_not_stop_a_release(): void
    {
        $run = $this->payrollRun();
        $this->payslipOn($run, person: ['income_tax_number' => null, 'account_number' => null]);
        $run = $this->reconcile($run);

        $this->post(route('payroll.runs.release', $run->uuid))->assertSessionHasNoErrors();

        $this->assertSame(PayrollRunStatus::Released->value, $run->fresh()->status->value);
    }

    public function test_the_report_is_on_the_run_screen_rather_than_behind_a_button(): void
    {
        // A check nobody runs is a check that does not exist.
        $run = $this->payrollRun();
        $this->payslipOn($run, ['net_pay' => -50.00]);
        $run = $this->reconcile($run);

        $this->get(route('payroll.runs.show', $run->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('validation.blockers', 1)
                ->has('validation.findings'));
    }
}
