<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauDisbursement;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauOperationsService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The bureau's work queues.
 *
 * The dashboard was a list of client cards, which answers "who are my clients" — a question nobody
 * has. With forty clients on three pay cycles, "what is outstanding" could only be answered by
 * opening each client in turn and remembering what you saw, which is not a thing anybody does
 * reliably on the 24th.
 */
class BureauOperationsDashboardTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'ops+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->agent);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ops Client ' . Str::random(5),
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
            'first_name' => 'Lebo', 'last_name' => 'Sithole',
            'start_date' => '2025-01-01', 'basic_salary' => 15000,
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'tax_number' => (string) random_int(1000000000, 9999999999),
            'bank_name' => 'FNB', 'branch_code' => '250655', 'account_number' => '62' . random_int(100000000, 999999999),
        ], $attributes));
    }

    private function overview(?string $asAt = null): array
    {
        return app(BureauOperationsService::class)->overview(
            collect([$this->client]),
            $asAt ? Carbon::parse($asAt) : null,
        );
    }

    public function test_a_client_with_no_run_covering_today_is_due(): void
    {
        $work = $this->overview('2026-03-15');

        $this->assertSame(1, $work['due']['count']);
        $this->assertSame($this->client->name, $work['due']['rows'][0]['client']);
        $this->assertSame('monthly', $work['due']['rows'][0]['frequency']);
    }

    public function test_a_client_whose_period_is_already_covered_is_not_due(): void
    {
        app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]);

        $this->assertSame(0, $this->overview('2026-03-15')['due']['count']);
    }

    public function test_a_client_on_two_cycles_is_due_twice(): void
    {
        $this->hire();
        $this->hire(['first_name' => 'Wage', 'pay_frequency' => 'weekly']);

        // A single "has a run this month" check would call this client done while the wage staff
        // go unpaid.
        $work = $this->overview('2026-03-15');

        $this->assertSame(2, $work['due']['count']);
        $this->assertEqualsCanonicalizing(
            ['monthly', 'weekly'],
            array_column($work['due']['rows'], 'frequency'),
        );
    }

    public function test_a_processed_run_is_waiting_for_approval(): void
    {
        $this->hire();
        $run = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]);
        app(BureauPayRunService::class)->process($run);

        $work = $this->overview('2026-03-15');

        $this->assertSame(1, $work['awaiting']['count']);
        $this->assertSame($run->uuid, $work['awaiting']['rows'][0]['run_uuid']);
    }

    public function test_a_run_that_cannot_be_finalised_is_named_with_its_reason(): void
    {
        $employee = $this->hire();
        $run = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]);
        app(BureauPayRunService::class)->process($run);

        // Somebody backdated onto a run they do not belong on.
        $employee->forceFill(['start_date' => '2026-06-01'])->save();

        $work = $this->overview('2026-03-15');

        $this->assertSame(1, $work['blocked']['count']);
        $this->assertStringContainsString('after this period ends', $work['blocked']['rows'][0]['first']);
    }

    public function test_a_finalised_month_with_no_emp201_is_outstanding(): void
    {
        $this->hire();
        $run = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => Carbon::now()->startOfMonth()->toDateString(),
            'period_end' => Carbon::now()->endOfMonth()->toDateString(),
            'tax_year' => 2027,
        ]);
        app(BureauPayRunService::class)->process($run);
        app(BureauPayRunService::class)->approve($run->fresh());
        app(BureauPayRunService::class)->finalise($run->fresh());

        $work = $this->overview();

        // The return is due by the 7th and the penalty is a percentage of what was owed, so a month
        // that quietly went unfiled is the most expensive thing on the page.
        $this->assertSame(1, $work['emp201']['count']);
        $this->assertSame(Carbon::now()->format('Y-m'), $work['emp201']['rows'][0]['period']);
    }

    public function test_a_finalised_run_whose_money_has_not_moved_is_listed(): void
    {
        $this->hire();
        $run = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => Carbon::now()->startOfMonth()->toDateString(),
            'period_end' => Carbon::now()->endOfMonth()->toDateString(),
            'tax_year' => 2027,
        ]);
        app(BureauPayRunService::class)->process($run);
        app(BureauPayRunService::class)->approve($run->fresh());
        app(BureauPayRunService::class)->finalise($run->fresh());

        $this->assertSame(1, $this->overview()['payments']['count']);

        BureauDisbursement::create([
            'uuid' => (string) Str::uuid(), 'bureau_pay_run_id' => $run->id,
            'bureau_company_id' => $this->client->id, 'provider' => 'test',
            'reference' => 'BATCH-1', 'status' => 'paid', 'count' => 1,
            'total_amount' => 100, 'company_id' => $this->companyId,
        ]);

        $this->assertSame(0, $this->overview()['payments']['count']);
    }

    public function test_a_rejected_batch_is_surfaced(): void
    {
        $run = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]);

        BureauDisbursement::create([
            'uuid' => (string) Str::uuid(), 'bureau_pay_run_id' => $run->id,
            'bureau_company_id' => $this->client->id, 'provider' => 'test',
            'reference' => 'BATCH-2', 'status' => 'failed', 'count' => 4,
            'total_amount' => 48000, 'company_id' => $this->companyId,
        ]);

        // Nobody was paid, and nothing else in the system says so.
        $work = $this->overview();

        $this->assertSame(1, $work['failed']['count']);
        $this->assertSame('BATCH-2', $work['failed']['rows'][0]['reference']);
    }

    public function test_an_employee_near_the_end_of_the_incentive_is_flagged(): void
    {
        $employee = $this->hire(['basic_salary' => 3000, 'date_of_birth' => '2004-01-01']);

        $claims = app(EtiClaimService::class);
        for ($i = 1; $i <= 22; $i++) {
            $claims->record(
                EtiClaim::BUREAU, (int) $employee->id,
                Carbon::parse('2024-01-01')->addMonths($i)->format('Y-m'),
                '2024-01-01', '2024-01-31', 2024, 1500.0, 3000.0, 21, $i,
                null, ['bureau_company_id' => $this->client->id],
            );
        }

        $work = $this->overview();

        $this->assertSame(1, $work['eti_ending']['count']);
        $this->assertSame(2, $work['eti_ending']['rows'][0]['remaining']);
    }

    public function test_an_employee_with_months_to_spare_is_not_flagged(): void
    {
        $employee = $this->hire(['basic_salary' => 3000, 'date_of_birth' => '2004-01-01']);

        $claims = app(EtiClaimService::class);
        for ($i = 1; $i <= 6; $i++) {
            $claims->record(
                EtiClaim::BUREAU, (int) $employee->id,
                Carbon::parse('2025-01-01')->addMonths($i)->format('Y-m'),
                '2025-01-01', '2025-01-31', 2025, 1500.0, 3000.0, 21, $i,
                null, ['bureau_company_id' => $this->client->id],
            );
        }

        $this->assertSame(0, $this->overview()['eti_ending']['count']);
    }

    public function test_an_agent_with_no_clients_gets_empty_queues(): void
    {
        $work = app(BureauOperationsService::class)->overview(collect());

        foreach ($work as $panel) {
            $this->assertSame(0, $panel['count']);
            $this->assertSame([], $panel['rows']);
        }
    }

    public function test_every_panel_carries_the_rows_behind_its_count(): void
    {
        $this->hire();
        $run = app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]);
        app(BureauPayRunService::class)->process($run);

        // A count with no rows behind it sends somebody hunting through forty clients for the three
        // that need them, which is the work the dashboard was supposed to remove.
        foreach ($this->overview('2026-03-15') as $key => $panel) {
            $this->assertCount($panel['count'], $panel['rows'], "Panel {$key} miscounts its rows.");
        }
    }
}
