<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The twenty-four months are months claimed, not months employed.
 *
 * Deriving them from a start date answers a different question, and gets wrong every case where the
 * two diverge — a rehire, an employment gap, a month nobody claimed, an employee arriving with claims
 * already made elsewhere. It also stopped the incentive at month 25 of tenure, so an employer who
 * missed three months lost three months of benefit they were entitled to.
 *
 * The ledger is what makes the answer defensible to SARS, and the reason recorded against the months
 * that claimed nothing is what makes it answerable to the client two years later.
 */
class EtiClaimLedgerTest extends TestCase
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
                    // The published bands, plus the bounds that used to be hard-coded in PHP.
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

        $this->agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'eti+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => (int) $companyId,
        ]);
        $this->actingAs($this->agent);

        $this->client = BureauCompany::create([
            'agent_id' => $this->agent->id, 'name' => 'ETI Co',
            'pay_frequency' => 'monthly', 'created_by' => $this->agent->id,
        ]);
    }

    private function hire(array $attributes = []): BureauEmployee
    {
        return app(BureauEmployeeService::class)->create($this->client, array_merge([
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu',
            'start_date' => '2026-03-01', 'basic_salary' => 3000,
            // Twenty-two on the run dates below, so comfortably inside the qualifying age band.
            'date_of_birth' => '2004-01-01',
        ], $attributes));
    }

    private function runFor(string $start, string $end): \App\Modules\HR\Payroll\Models\Bureau\BureauPayRun
    {
        return app(BureauPayRunService::class)->create($this->client, [
            'frequency' => 'monthly', 'period_start' => $start, 'period_end' => $end, 'tax_year' => 2027,
        ]);
    }

    private function claims(): EtiClaimService
    {
        return app(EtiClaimService::class);
    }

    // ── Adding an intern starts the clock ────────────────────────────────────

    public function test_adding_an_intern_starts_eti_tracking_without_anybody_ticking_a_box(): void
    {
        $intern = $this->hire(['employment_category' => EmploymentCategory::INTERN]);

        // Forgetting the opt-in is invisible — nothing looks wrong, the claim simply never happens —
        // so the category the incentive exists for switches it on by itself.
        $this->assertTrue($intern->eti_opt_in);
        $this->assertSame('2026-03-01', $intern->eti_start_date?->toDateString());
    }

    public function test_a_learnership_does_the_same(): void
    {
        $learner = $this->hire(['employment_category' => EmploymentCategory::LEARNERSHIP]);

        $this->assertTrue($learner->eti_opt_in);
    }

    public function test_promoting_an_intern_does_not_stop_the_months_they_have_left(): void
    {
        $intern = $this->hire(['employment_category' => EmploymentCategory::INTERN]);

        app(BureauEmployeeService::class)->update($intern, ['employment_category' => EmploymentCategory::PERMANENT]);

        // A promotion is not a reason to abandon an entitlement the employer still has.
        $this->assertTrue($intern->fresh()->eti_opt_in);
    }

    public function test_an_intern_arriving_by_spreadsheet_is_tracked_too(): void
    {
        app(BureauEmployeeService::class)->import($this->client, [[
            'employee_number' => 'IMP-1', 'first_name' => 'Zanele', 'last_name' => 'Dube',
            'start_date' => '2026-03-01', 'employment_category' => EmploymentCategory::INTERN,
        ]]);

        $this->assertTrue($this->client->employees()->where('employee_number', 'IMP-1')->first()->eti_opt_in);
    }

    // ── The ledger counts claims, not tenure ─────────────────────────────────

    public function test_every_processed_month_is_recorded_with_its_claim_number(): void
    {
        $employee = $this->hire(['employment_category' => EmploymentCategory::INTERN]);

        app(BureauPayRunService::class)->process($this->runFor('2026-03-01', '2026-03-31'));
        app(BureauPayRunService::class)->process($this->runFor('2026-04-01', '2026-04-30'));

        $ledger = EtiClaim::query()->forEmployee(EtiClaim::BUREAU, $employee->id)
            ->orderBy('period_key')->get();

        $this->assertCount(2, $ledger);
        $this->assertSame([1, 2], $ledger->pluck('claim_month')->all());
        $this->assertSame(['2026-03', '2026-04'], $ledger->pluck('period_key')->all());
        $this->assertTrue($ledger->every(fn ($c) => $c->eligible));
    }

    public function test_reprocessing_a_run_does_not_burn_a_second_month(): void
    {
        $employee = $this->hire(['employment_category' => EmploymentCategory::INTERN]);
        $run = $this->runFor('2026-03-01', '2026-03-31');

        app(BureauPayRunService::class)->process($run);
        app(BureauPayRunService::class)->process($run->fresh());
        app(BureauPayRunService::class)->process($run->fresh());

        // Pressing process three times used to advance the employee three months. The period is
        // excluded from its own count, so the run corrects its claim rather than adding one.
        $this->assertSame(1, $this->claims()->claimedMonths(EtiClaim::BUREAU, $employee->id));
        $this->assertSame(1, EtiClaim::query()->forEmployee(EtiClaim::BUREAU, $employee->id)->count());
    }

    public function test_a_gap_in_claiming_does_not_consume_months(): void
    {
        $employee = $this->hire(['employment_category' => EmploymentCategory::INTERN]);

        // March runs. April and May are never run — the client joined late, or nobody claimed.
        app(BureauPayRunService::class)->process($this->runFor('2026-03-01', '2026-03-31'));
        app(BureauPayRunService::class)->process($this->runFor('2026-06-01', '2026-06-30'));

        // Tenure says month 4. Claims say month 2, which is what SARS counts and what the employer
        // is entitled to — the old behaviour silently threw those two months away.
        $this->assertSame(2, $this->claims()->claimedMonths(EtiClaim::BUREAU, $employee->id));
        $this->assertSame(2, EtiClaim::query()->forEmployee(EtiClaim::BUREAU, $employee->id)
            ->where('period_key', '2026-06')->first()->claim_month);
        $this->assertSame(22, $this->claims()->remainingMonths(EtiClaim::BUREAU, $employee->id));
    }

    public function test_a_month_that_claims_nothing_records_why(): void
    {
        // Far too well paid to qualify, which is the commonest reason of all.
        $employee = $this->hire([
            'employment_category' => EmploymentCategory::INTERN, 'basic_salary' => 40000,
        ]);

        app(BureauPayRunService::class)->process($this->runFor('2026-03-01', '2026-03-31'));

        $claim = EtiClaim::query()->forEmployee(EtiClaim::BUREAU, $employee->id)->first();

        $this->assertNotNull($claim, 'A month that claimed nothing is still a fact the history must carry.');
        $this->assertFalse($claim->eligible);
        $this->assertNull($claim->claim_month, 'A month that claimed nothing does not consume one of the twenty-four.');
        $this->assertStringContainsString('outside the qualifying bands', $claim->reason);
        $this->assertSame(0, $this->claims()->claimedMonths(EtiClaim::BUREAU, $employee->id));
    }

    public function test_the_incentive_stops_once_the_months_are_used_up(): void
    {
        $employee = $this->hire(['employment_category' => EmploymentCategory::INTERN]);

        // Twenty-four consecutive months already claimed in this employee's history.
        for ($i = 1; $i <= 24; $i++) {
            $this->claims()->record(
                EtiClaim::BUREAU, $employee->id,
                Carbon::parse('2023-01-01')->addMonths($i)->format('Y-m'),
                '2023-01-01', '2023-01-31', 2023, 500.0, 3000.0, 22, $i,
            );
        }

        $this->assertTrue($this->claims()->isExhausted(EtiClaim::BUREAU, $employee->id));

        app(BureauPayRunService::class)->process($this->runFor('2026-03-01', '2026-03-31'));

        $claim = EtiClaim::query()->forEmployee(EtiClaim::BUREAU, $employee->id)
            ->where('period_key', '2026-03')->first();

        $this->assertFalse($claim->eligible);
        $this->assertStringContainsString('qualifying months have been claimed', $claim->reason);
    }

    public function test_an_employee_never_claims_the_same_month_twice(): void
    {
        $employee = $this->hire(['employment_category' => EmploymentCategory::INTERN]);

        $this->claims()->record(EtiClaim::BUREAU, $employee->id, '2026-03', '2026-03-01', '2026-03-31', 2027, 500.0, 3000.0, 22, 1);
        $this->claims()->record(EtiClaim::BUREAU, $employee->id, '2026-03', '2026-03-01', '2026-03-31', 2027, 700.0, 3200.0, 22, 1);

        // updateOrCreate on (employee, period), backed by a unique index — a second claim for one
        // month corrects the first rather than adding to it.
        $this->assertSame(1, EtiClaim::query()->forEmployee(EtiClaim::BUREAU, $employee->id)->count());
        $this->assertSame('700.00', (string) EtiClaim::query()->forEmployee(EtiClaim::BUREAU, $employee->id)->first()->amount);
    }

    // ── What the bureau needs to see coming ──────────────────────────────────

    public function test_employees_near_the_end_of_their_entitlement_can_be_found(): void
    {
        $nearlyDone = $this->hire(['employment_category' => EmploymentCategory::INTERN]);
        $justStarted = $this->hire(['first_name' => 'Thandi', 'employment_category' => EmploymentCategory::INTERN]);

        for ($i = 1; $i <= 22; $i++) {
            $this->claims()->record(
                EtiClaim::BUREAU, $nearlyDone->id,
                Carbon::parse('2024-01-01')->addMonths($i)->format('Y-m'),
                '2024-01-01', '2024-01-31', 2024, 500.0, 3000.0, 22, $i,
                null, ['bureau_company_id' => $this->client->id],
            );
        }
        $this->claims()->record(
            EtiClaim::BUREAU, $justStarted->id, '2026-03', '2026-03-01', '2026-03-31', 2027,
            500.0, 3000.0, 22, 1, null, ['bureau_company_id' => $this->client->id],
        );

        $summary = $this->claims()->summaryFor(EtiClaim::BUREAU, $nearlyDone->id);

        // The bureau needs warning before the last month, not after it — the incentive is part of
        // what a client budgets for.
        $this->assertSame(22, $summary['claimed']);
        $this->assertSame(2, $summary['remaining']);
        $this->assertFalse($summary['exhausted']);
        $this->assertSame(1, $this->claims()->summaryFor(EtiClaim::BUREAU, $justStarted->id)['claimed']);
    }
}
