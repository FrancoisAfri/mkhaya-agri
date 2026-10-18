<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\PayrollSetting;
use App\Modules\HR\Payroll\Services\PayrollService;
use Illuminate\Support\Facades\DB;

/**
 * One company, several pay cycles.
 *
 * 4e31bc53 made a weekly or fortnightly payroll calculate correctly, but the frequency it read was
 * PayrollSetting::pay_frequency — one value for the whole company. A firm with monthly salaried
 * staff and weekly wage earners therefore had to be wrong about one of them: the weekly people were
 * paid a month's salary each week and taxed as though they earned it fifty-two times a year.
 *
 * Frequency now belongs to the employee and to the run. What these tests hold down is the part that
 * is easy to get wrong once both exist at the same time — that a run pays its own cycle and nobody
 * else's, because the alternative is paying the same weeks twice.
 */
class MultiFrequencyRunTest extends PayrollTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PayrollSetting::query()->delete();
        PayrollSetting::create(['pay_frequency' => 'monthly']);
    }

    /**
     * An employee with a package and a pay cycle of their own.
     *
     * A null frequency means "leave the column alone" — it is NOT NULL with a default, so this is
     * the state an employee who predates the column is actually in, not a null in the row.
     */
    private function employeeOn(?string $frequency, float $package = 30000): User
    {
        $user = User::factory()->create(['status' => 1]);

        DB::table('hr_people')->insert(array_filter([
            'user_id'         => $user->id,
            'first_name'      => 'Test',
            'surname'         => ucfirst($frequency ?? 'default'),
            'monthly_package' => $package,
            'pay_frequency'   => $frequency,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], fn ($value) => $value !== null));

        return $user;
    }

    private function runOn(string $frequency, string $start, string $end): PayrollRun
    {
        return app(PayrollService::class)->createPayrollRun([
            'name'         => ucfirst($frequency) . ' run',
            'period_start' => $start,
            'period_end'   => $end,
            'frequency'    => $frequency,
        ], $this->hrManager->id);
    }

    // ── A run pays one cycle ──────────────────────────────────────────────────

    public function test_a_weekly_employee_is_kept_off_the_monthly_run(): void
    {
        $weekly  = $this->employeeOn('weekly');
        $monthly = $this->employeeOn('monthly');

        $run = $this->runOn('monthly', '2026-09-01', '2026-09-30');

        $result = app(PayrollService::class)->generatePayrollItems($run, [$weekly->id, $monthly->id]);

        $this->assertSame(1, $result['added']);
        $this->assertCount(1, $result['mismatched']);
        $this->assertDatabaseMissing('payslips', ['payroll_run_id' => $run->id, 'employee_id' => $weekly->id]);
        $this->assertDatabaseHas('payslips', ['payroll_run_id' => $run->id, 'employee_id' => $monthly->id]);
    }

    public function test_the_people_left_off_are_named_rather_than_dropped_quietly(): void
    {
        // A count that silently disagrees with what was selected is discovered on payday.
        $weekly = $this->employeeOn('weekly');

        $run = $this->runOn('monthly', '2026-09-01', '2026-09-30');

        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.add-employees', $run->uuid), ['employee_ids' => [$weekly->id]])
            ->assertRedirect();

        $this->assertStringContainsString('Test Weekly', session('warning'));
    }

    public function test_somebody_who_predates_the_column_takes_its_default(): void
    {
        $unset = $this->employeeOn(null);

        $run = $this->runOn('monthly', '2026-09-01', '2026-09-30');

        $this->assertSame(1, app(PayrollService::class)->generatePayrollItems($run, [$unset->id])['added']);
    }

    public function test_a_new_hire_at_a_weekly_company_is_weekly_not_monthly(): void
    {
        /*
         * The trap in the column's default. Monthly is right for almost everybody and wrong in the
         * one case that matters: a company that pays weekly hires somebody, the row defaults to
         * monthly, and that person is filtered out of every weekly run — never paid, with nothing
         * on screen to say why. Existing rows are handled by the migration's backfill; this is the
         * same rule for every row created after it.
         */
        PayrollSetting::query()->update(['pay_frequency' => 'weekly']);

        $hire = HRPerson::create([
            'user_id' => ($u = User::factory()->create(['status' => 1]))->id,
            'first_name' => 'New', 'surname' => 'Hire', 'monthly_package' => 30000,
        ]);

        $this->assertSame('weekly', $hire->fresh()->pay_frequency);

        $run = $this->runOn('weekly', '2026-09-07', '2026-09-13');
        $this->assertSame(1, app(PayrollService::class)->generatePayrollItems($run, [$u->id])['added']);
    }

    public function test_a_cycle_stated_at_hire_is_not_overwritten_by_the_company_default(): void
    {
        $hire = HRPerson::create([
            'user_id' => User::factory()->create(['status' => 1])->id,
            'first_name' => 'Wage', 'surname' => 'Earner', 'monthly_package' => 30000,
            'pay_frequency' => 'weekly',
        ]);

        $this->assertSame('weekly', $hire->fresh()->pay_frequency);
    }

    public function test_the_same_person_can_be_paid_on_their_own_cycle(): void
    {
        $weekly = $this->employeeOn('weekly');

        $run = $this->runOn('weekly', '2026-09-07', '2026-09-13');

        $this->assertSame(1, app(PayrollService::class)->generatePayrollItems($run, [$weekly->id])['added']);
    }

    // ── The salary on the payslip is the period's, not the month's ────────────

    public function test_a_weekly_payslip_carries_one_week_of_the_package(): void
    {
        $weekly = $this->employeeOn('weekly', 52000);

        $run = $this->runOn('weekly', '2026-09-07', '2026-09-13');
        app(PayrollService::class)->generatePayrollItems($run, [$weekly->id]);

        // 52 000 a month is 624 000 a year, which is 12 000 a week — not 52 000.
        $this->assertEqualsWithDelta(
            12000.00,
            (float) DB::table('payslips')->where('payroll_run_id', $run->id)->value('basic_salary'),
            0.01,
        );
    }

    public function test_the_company_setting_no_longer_decides_an_individual(): void
    {
        // The company is monthly; this person is not. Before, the run read the setting for
        // everybody and put a full month's salary on a weekly payslip.
        $weekly = $this->employeeOn('weekly', 52000);
        $run = $this->runOn('weekly', '2026-09-07', '2026-09-13');

        $this->assertSame('weekly', app(PayrollService::class)->frequencyFor(DB::table('hr_people')->where('user_id', $weekly->id)->first()));
        $this->assertSame('monthly', app(PayrollService::class)->payFrequency());
    }

    public function test_an_unreadable_frequency_falls_back_without_being_silent(): void
    {
        DB::table('hr_people')->insert([
            'user_id' => ($u = User::factory()->create(['status' => 1]))->id,
            'first_name' => 'Odd', 'surname' => 'Value',
            'monthly_package' => 10000, 'pay_frequency' => 'quarterly',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withSomeOfArgs('payroll: unknown pay frequency on employee, falling back');

        $this->assertSame('monthly', app(PayrollService::class)->frequencyFor(
            DB::table('hr_people')->where('user_id', $u->id)->first(),
        ));
    }

    // ── The picker only offers who the run can pay ───────────────────────────

    public function test_the_roster_picker_leaves_out_everybody_on_another_cycle(): void
    {
        $weekly  = $this->employeeOn('weekly');
        $monthly = $this->employeeOn('monthly');

        $run = $this->runOn('monthly', '2026-09-01', '2026-09-30');

        $this->actingAs($this->hrManager)
            ->get(route('payroll.runs.show', $run->uuid))
            ->assertInertia(fn ($page) => $page->where(
                'availableEmployees',
                fn ($available) => collect($available)->pluck('id')->contains($monthly->id)
                    && ! collect($available)->pluck('id')->contains($weekly->id),
            ));
    }

    // ── Creating a run ───────────────────────────────────────────────────────

    public function test_a_run_records_the_cycle_it_pays(): void
    {
        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.store'), [
                'name'         => 'Week 37',
                'period_start' => '2026-09-07',
                'period_end'   => '2026-09-13',
                'frequency'    => 'weekly',
            ])->assertRedirect();

        $this->assertDatabaseHas('payroll_runs', ['name' => 'Week 37', 'frequency' => 'weekly']);
    }

    public function test_a_run_created_without_one_takes_the_company_default(): void
    {
        // Every run made before runs carried a frequency at all is in this position.
        $this->actingAs($this->hrManager)
            ->post(route('payroll.runs.store'), [
                'name'         => 'September 2026',
                'period_start' => '2026-09-01',
                'period_end'   => '2026-09-30',
            ])->assertRedirect();

        $this->assertDatabaseHas('payroll_runs', ['name' => 'September 2026', 'frequency' => 'monthly']);
    }

    public function test_the_create_screen_offers_each_cycle_with_its_current_period(): void
    {
        $this->actingAs($this->hrManager)
            ->get(route('payroll.runs.create'))
            ->assertInertia(fn ($page) => $page
                ->has('periods.weekly.period_start')
                ->has('periods.fortnightly.period_end')
                ->has('periods.monthly.headcount')
                ->where('defaultFrequency', 'monthly'));
    }
}
