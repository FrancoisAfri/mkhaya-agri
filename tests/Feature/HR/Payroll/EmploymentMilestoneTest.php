<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\EmploymentMilestone;
use App\Modules\HR\Payroll\Models\EmploymentMilestoneRule;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Notifications\EmploymentMilestoneReached;
use App\Modules\HR\Payroll\Services\EmploymentMilestoneService;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Employment milestones — the notices a payroll administrator has to act on.
 *
 * The requirement arrived with a disagreement inside it: the minutes said one month, the follow-up
 * said one year. Both are right for somebody, which is why these are configured rather than fixed.
 */
class EmploymentMilestoneTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private BureauCompany $client;
    private User $agent;

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

        $this->agent = $this->makeUser();

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Milestone Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->agent->id, 'created_by' => $this->agent->id,
            'pay_frequency' => 'monthly',
        ]);
    }

    private function makeUser(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'milestone+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
    }

    private function rule(int $months, array $overrides = []): EmploymentMilestoneRule
    {
        return EmploymentMilestoneRule::create(array_merge([
            'months' => $months, 'label' => "{$months}-month check", 'is_active' => true,
            'bureau_company_id' => $this->client->id, 'company_id' => $this->companyId,
        ], $overrides));
    }

    private function employee(string $startDate, array $overrides = []): BureauEmployee
    {
        return $this->client->employees()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'employee_number' => 'EMP' . random_int(1000, 9999),
            'first_name' => 'Thandi', 'last_name' => 'Mokoena',
            'start_date' => $startDate,
            'status' => BureauEmployee::STATUS_ACTIVE,
            'company_id' => $this->companyId,
        ], $overrides));
    }

    private function scan(string $asAt): \Illuminate\Support\Collection
    {
        return app(EmploymentMilestoneService::class)->scan(Carbon::parse($asAt), $this->client);
    }

    public function test_a_milestone_is_raised_once_the_employee_reaches_it(): void
    {
        $this->rule(12);
        $employee = $this->employee('2025-08-01');

        $this->assertCount(0, $this->scan('2026-07-15'), 'Eleven months in, there is nothing to say yet.');

        $raised = $this->scan('2026-08-01');

        $this->assertCount(1, $raised);
        $this->assertSame(12, $raised->first()->months);
        $this->assertSame((int) $employee->id, (int) $raised->first()->employee_id);
    }

    public function test_scanning_again_says_nothing_further(): void
    {
        $this->rule(12);
        $this->employee('2025-08-01');

        $this->scan('2026-08-01');

        // A daily scan that resends yesterday's reminder is a scan people learn to filter out.
        $this->assertCount(0, $this->scan('2026-08-02'));
        $this->assertCount(0, $this->scan('2026-09-30'));
        $this->assertSame(1, EmploymentMilestone::query()->where('months', 12)->count());
    }

    public function test_a_missed_day_does_not_lose_the_notice(): void
    {
        $this->rule(12);
        $this->employee('2025-08-01');

        // Nobody scanned on the anniversary — the server was down, or it fell on a Sunday.
        $raised = $this->scan('2026-08-20');

        $this->assertCount(1, $raised, 'The milestone is due, not expired.');
    }

    public function test_lead_days_raise_it_before_the_date(): void
    {
        $this->rule(24, ['lead_days' => 60]);
        $this->employee('2024-09-01');

        // The run this affects is prepared before the incentive actually expires.
        $this->assertCount(1, $this->scan('2026-07-15'));
    }

    public function test_a_rule_can_be_limited_to_the_categories_it_is_for(): void
    {
        $this->rule(24, ['categories' => [EmploymentCategory::INTERN, EmploymentCategory::LEARNERSHIP]]);
        $this->employee('2024-01-01', ['employment_category' => EmploymentCategory::PERMANENT]);

        $this->assertCount(0, $this->scan('2026-08-01'), 'A long-serving permanent employee is not an incentive review.');

        $this->employee('2024-01-01', ['employment_category' => EmploymentCategory::INTERN]);

        $this->assertCount(1, $this->scan('2026-08-01'));
    }

    public function test_an_intern_notice_carries_their_incentive_position(): void
    {
        $this->rule(12, ['categories' => [EmploymentCategory::INTERN]]);
        $employee = $this->employee('2025-08-01', ['employment_category' => EmploymentCategory::INTERN]);

        $claims = app(EtiClaimService::class);
        for ($i = 1; $i <= 9; $i++) {
            $claims->record(
                EtiClaim::BUREAU, (int) $employee->id,
                Carbon::parse('2025-08-01')->addMonths($i)->format('Y-m'),
                '2025-08-01', '2025-08-31', 2026, 1500.0, 3000.0, 22, $i,
            );
        }

        $milestone = $this->scan('2026-08-01')->first();

        $this->assertTrue($milestone->eti_tracked);
        $this->assertSame(9, $milestone->eti_months_claimed);
        $this->assertSame(15, $milestone->eti_months_remaining);
        $this->assertStringContainsString('9 of 24 months used', $milestone->reason);
    }

    public function test_an_employee_imported_with_years_of_service_gets_every_milestone_they_passed(): void
    {
        foreach ([1, 3, 6, 12, 24] as $months) {
            $this->rule($months);
        }
        $this->employee('2024-08-01');

        // Reporting only the newest would leave the earlier reviews — including the incentive
        // position — unexamined, which is the one thing these notices exist to surface.
        $this->assertCount(5, $this->scan('2026-08-01'));
    }

    public function test_somebody_without_a_start_date_is_skipped_rather_than_guessed_at(): void
    {
        $this->rule(12);
        $this->employee('2025-08-01', ['start_date' => null]);

        $this->assertCount(0, $this->scan('2026-08-01'));
    }

    public function test_a_terminated_employee_raises_nothing(): void
    {
        $this->rule(12);
        $this->employee('2025-08-01', ['status' => BureauEmployee::STATUS_TERMINATED]);

        $this->assertCount(0, $this->scan('2026-08-01'));
    }

    public function test_an_inactive_rule_raises_nothing(): void
    {
        $this->rule(12, ['is_active' => false]);
        $this->employee('2025-08-01');

        $this->assertCount(0, $this->scan('2026-08-01'));
    }

    public function test_a_client_with_its_own_rules_does_not_also_get_the_tenant_defaults(): void
    {
        EmploymentMilestoneRule::create([
            'months' => 1, 'label' => 'Tenant default', 'is_active' => true,
            'bureau_company_id' => null, 'company_id' => $this->companyId,
        ]);
        $this->rule(12);
        $this->employee('2025-08-01');

        // Otherwise a client who deliberately removed the probation notice would still be sent it,
        // and there would be no way to switch anything off.
        $raised = $this->scan('2026-08-01');

        $this->assertCount(1, $raised);
        $this->assertSame(12, $raised->first()->months);
    }

    public function test_the_notice_reaches_somebody_and_is_only_sent_once(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $agent = $this->agent;

        $this->rule(12);
        $this->employee('2025-08-01');

        $service = app(EmploymentMilestoneService::class);
        $raised = $this->scan('2026-08-01');

        $this->assertSame(1, $service->notify($raised));
        $this->assertSame(0, $service->notify($raised->map->fresh()), 'Already notified.');

        \Illuminate\Support\Facades\Notification::assertSentTo($agent, EmploymentMilestoneReached::class);
    }

    public function test_the_in_house_payroll_is_scanned_too(): void
    {
        // The tenant's own default rule — the in-house payroll has no client company to hang one on.
        EmploymentMilestoneRule::create([
            'months' => 12, 'label' => 'First anniversary', 'is_active' => true,
            'bureau_company_id' => null, 'company_id' => $this->companyId,
        ]);

        $user = $this->makeUser();
        $personId = DB::table('hr_people')->insertGetId([
            'user_id' => $user->id, 'first_name' => 'Lerato', 'surname' => 'Dube',
            'employee_number' => 'HR' . random_int(1000, 9999),
            'employment_category' => EmploymentCategory::INTERN,
            // A Unix timestamp, because that is what this legacy column holds.
            'date_joined' => Carbon::parse('2025-08-01')->timestamp,
            'company_id' => $this->companyId, 'second_manager_id' => 0, 'eligible_to_drive' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $raised = app(EmploymentMilestoneService::class)->scan(Carbon::parse('2026-08-01'));
        $mine = $raised->firstWhere('employee_id', $personId);

        // An intern is an intern whichever engine runs their payroll, and the incentive expiring at
        // twenty-four months costs the employer the same money either way.
        $this->assertNotNull($mine, 'The in-house payroll was scanned as well as the bureau.');
        $this->assertSame(EmploymentMilestone::HR, $mine->employee_type);
        $this->assertTrue($mine->eti_tracked);
        $this->assertSame(24, $mine->eti_months_remaining);
    }

    public function test_the_defaults_can_be_installed_and_are_not_duplicated(): void
    {
        $service = app(EmploymentMilestoneService::class);

        $this->assertSame(count(EmploymentMilestoneRule::DEFAULTS), $service->installDefaults($this->client));
        $this->assertSame(0, $service->installDefaults($this->client));
    }
}
