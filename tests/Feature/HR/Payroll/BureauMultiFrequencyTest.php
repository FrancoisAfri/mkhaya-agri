<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Support\PayPeriod;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * One client, several pay cycles.
 *
 * The bug this closes did not produce a wrong number — it produced no number at all. A run's
 * frequency was copied from the company and could not be chosen, and process() then filters
 * employees to the run's frequency. So a weekly employee at a monthly client was excluded from every
 * run that could exist and was simply never paid: no error, no warning, no empty payslip to notice.
 *
 * The other half is the opposite risk. Once a client can have two cycles, two runs can cover the
 * same days, and the same person can be paid twice for the same week — so overlapping runs on one
 * cycle are refused, by name, before anybody presses process.
 */
class BureauMultiFrequencyTest extends TestCase
{
    use DatabaseTransactions;

    private User $agent;
    private BureauCompany $client;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        app(CurrentCompany::class)->set((int) $companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(['code_name' => 'payroll_bureau'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Payroll Bureau', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();

        // The engine needs a tax year for the period under test; the shared test database is not
        // guaranteed to carry one for a future year, and this test is about cycles, not brackets.
        foreach ([2026, 2027] as $taxYear) {
            DB::table('sa_tax_years')->updateOrInsert(
                ['country' => 'ZA', 'tax_year' => $taxYear],
                [
                    'starts_on' => ($taxYear - 1) . '-03-01', 'ends_on' => $taxYear . '-02-28',
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

        $this->agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'freq+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => (int) $companyId,
        ]);
        $this->actingAs($this->agent);

        // A monthly client, which is what makes the weekly staff below the interesting case.
        $this->client = BureauCompany::create([
            'agent_id' => $this->agent->id, 'name' => 'Mixed Cycle Co',
            'pay_frequency' => 'monthly', 'created_by' => $this->agent->id,
        ]);
    }

    private function employee(string $first, ?string $frequency): BureauEmployee
    {
        return BureauEmployee::create([
            'bureau_company_id' => $this->client->id,
            'employee_number'   => 'MF' . Str::padLeft((string) random_int(1, 99999), 5, '0'),
            'first_name'        => $first, 'last_name' => 'Ndlovu',
            'status'            => BureauEmployee::STATUS_ACTIVE,
            'start_date'        => '2024-01-01', 'basic_salary' => 20000,
            'pay_frequency'     => $frequency,
        ]);
    }

    private function runs(): BureauPayRunService
    {
        return app(BureauPayRunService::class);
    }

    // ── The run's own cycle ──────────────────────────────────────────────────

    public function test_a_run_can_be_created_on_a_cycle_the_company_does_not_use(): void
    {
        $run = $this->runs()->create($this->client, [
            'frequency'    => 'weekly',
            'period_start' => '2026-03-02',
            'period_end'   => '2026-03-08',
        ]);

        // The client is monthly. Without this the run came out monthly and the weekly staff it was
        // created for were filtered straight back out of it.
        $this->assertSame('weekly', $run->frequency);
    }

    public function test_a_run_without_a_stated_cycle_still_falls_back_to_the_company(): void
    {
        $run = $this->runs()->create($this->client, [
            'period_start' => '2026-03-01',
            'period_end'   => '2026-03-31',
        ]);

        $this->assertSame('monthly', $run->frequency);
    }

    // ── Who lands on it ──────────────────────────────────────────────────────

    public function test_weekly_staff_at_a_monthly_client_are_paid(): void
    {
        $weekly = $this->employee('Sipho', 'weekly');
        $monthly = $this->employee('Thandi', 'monthly');
        $inherits = $this->employee('Nomsa', null);   // blank inherits the company's monthly

        $run = $this->runs()->create($this->client, [
            'frequency' => 'weekly', 'period_start' => '2026-03-02', 'period_end' => '2026-03-08',
        ]);
        $this->runs()->process($run);

        $paid = $run->fresh()->payslips()->pluck('bureau_employee_id');

        // The whole point: before this, no run existed that could ever include Sipho.
        $this->assertTrue($paid->contains($weekly->id), 'A weekly employee must be paid by the weekly run.');
        $this->assertFalse($paid->contains($monthly->id), 'A monthly employee does not belong on a weekly run.');
        $this->assertFalse($paid->contains($inherits->id), 'A blank frequency inherits the company, which is monthly.');
    }

    public function test_the_monthly_run_still_pays_everybody_else(): void
    {
        $weekly = $this->employee('Sipho', 'weekly');
        $monthly = $this->employee('Thandi', 'monthly');
        $inherits = $this->employee('Nomsa', null);

        $run = $this->runs()->create($this->client, [
            'frequency' => 'monthly', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        ]);
        $this->runs()->process($run);

        $paid = $run->fresh()->payslips()->pluck('bureau_employee_id');

        $this->assertTrue($paid->contains($monthly->id));
        $this->assertTrue($paid->contains($inherits->id));
        $this->assertFalse($paid->contains($weekly->id), 'Processing the monthly cycle must not sweep in weekly staff.');
    }

    public function test_a_legacy_frequency_spelling_still_matches(): void
    {
        // Both sides are canonicalised now. The run's side was compared raw, so a company left on
        // the old "biweekly" vocabulary matched nobody and produced an empty run.
        $this->client->forceFill(['pay_frequency' => 'biweekly'])->save();
        $employee = $this->employee('Sipho', 'fortnightly');

        $run = $this->runs()->create($this->client, [
            'frequency' => 'fortnightly', 'period_start' => '2026-03-02', 'period_end' => '2026-03-15',
        ]);
        $this->runs()->process($run);

        $this->assertTrue($run->fresh()->payslips()->pluck('bureau_employee_id')->contains($employee->id));
    }

    // ── Nobody is paid twice ─────────────────────────────────────────────────

    public function test_two_runs_of_one_cycle_cannot_cover_the_same_days(): void
    {
        $this->runs()->create($this->client, [
            'frequency' => 'weekly', 'period_start' => '2026-03-02', 'period_end' => '2026-03-08',
        ]);

        $this->expectException(ValidationException::class);

        // Overlaps by a single day, which is enough to pay somebody twice for it.
        $this->runs()->create($this->client, [
            'frequency' => 'weekly', 'period_start' => '2026-03-08', 'period_end' => '2026-03-14',
        ]);
    }

    public function test_different_cycles_may_cover_the_same_days(): void
    {
        $this->runs()->create($this->client, [
            'frequency' => 'monthly', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        ]);

        // Not a clash: they pay different people, and running both is the entire point.
        $weekly = $this->runs()->create($this->client, [
            'frequency' => 'weekly', 'period_start' => '2026-03-02', 'period_end' => '2026-03-08',
        ]);

        $this->assertSame('weekly', $weekly->frequency);
    }

    public function test_an_off_cycle_run_may_sit_inside_a_period_already_run(): void
    {
        $this->runs()->create($this->client, [
            'frequency' => 'monthly', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        ]);

        // A bonus or a termination settlement is by definition an extra payment inside a period
        // that has already been run, so it is exempt.
        $bonus = $this->runs()->create($this->client, [
            'frequency' => 'monthly', 'run_type' => 'bonus',
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        ]);

        $this->assertSame('bonus', $bonus->run_type);
    }

    // ── The dates a cycle implies ────────────────────────────────────────────

    public function test_a_period_end_follows_from_the_cycle(): void
    {
        $this->assertSame('2026-03-08', PayPeriod::endFor('weekly', '2026-03-02')->toDateString());
        $this->assertSame('2026-03-15', PayPeriod::endFor('fortnightly', '2026-03-02')->toDateString());
        $this->assertSame('2026-03-31', PayPeriod::endFor('monthly', '2026-03-01')->toDateString());
        // February is what makes adding thirty days wrong.
        $this->assertSame('2026-02-28', PayPeriod::endFor('monthly', '2026-02-01')->toDateString());
        // A cycle somebody started mid-month keeps its own shape.
        $this->assertSame('2026-04-14', PayPeriod::endFor('monthly', '2026-03-15')->toDateString());
    }

    public function test_overlap_is_inclusive_of_both_ends(): void
    {
        $this->assertTrue(PayPeriod::overlaps('2026-03-01', '2026-03-08', '2026-03-08', '2026-03-14'));
        $this->assertFalse(PayPeriod::overlaps('2026-03-01', '2026-03-07', '2026-03-08', '2026-03-14'));
    }

    // ── One client's people, and only theirs ─────────────────────────────────

    public function test_another_client_of_the_same_bureau_cannot_be_added_to_this_run(): void
    {
        /*
         * The picker only ever offers this client's staff, so this can only arrive as a hand-made
         * request — but the roster was the one lookup on the run that did not scope by client, and
         * an unscoped uuid was accepted. The person then appeared on a run belonging to somebody
         * else: paid out of the wrong client's bank account, on the wrong client's EMP201, and
         * visible to whoever approves that client's payroll.
         */
        $other = BureauCompany::create([
            'agent_id' => $this->agent->id, 'name' => 'Another Client',
            'pay_frequency' => 'monthly', 'created_by' => $this->agent->id,
        ]);
        $theirs = BureauEmployee::create([
            'bureau_company_id' => $other->id,
            'employee_number'   => 'OC' . Str::padLeft((string) random_int(1, 99999), 5, '0'),
            'first_name'        => 'Not', 'last_name' => 'Ours',
            'status'            => BureauEmployee::STATUS_ACTIVE,
            'start_date'        => '2024-01-01', 'basic_salary' => 20000,
        ]);

        $run = $this->runs()->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        ]);

        $this->post(route('payroll.bureau.runs.employees.add', [$this->client->uuid, $run->uuid]), [
            'employees' => [$theirs->uuid],
        ])->assertRedirect();

        $this->assertDatabaseMissing('bureau_pay_run_employees', [
            'bureau_pay_run_id' => $run->id,
            'bureau_employee_id' => $theirs->id,
        ]);
    }
}
