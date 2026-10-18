<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\KpiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hours worked, and the rates that cannot exist without them.
 *
 * `KpiService::recordExposure()` was written, tested at unit level and called by nothing, so
 * `sheq_exposure_hours` was empty on every installation. LTIFR, TRIR, DIFR and the severity rate
 * are each `numerator ÷ hours × multiplier`; with no denominator the module reported rates of zero
 * or refused to compute them, and nobody could tell which. These tests are about the path from the
 * screen to the table and back out through the KPI figures — not about the arithmetic, which
 * KpiTest already covers.
 */
class ExposureHoursTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $manager;

    private User $onlooker;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->manager = $this->userWith(['sheq.configure']);
        $this->onlooker = $this->userWith([]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'exp-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }

    private function siteId(): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'E'.Str::random(6), 'name' => 'Shaft', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_screen_posts_hours_into_the_table(): void
    {
        $this->actingAs($this->manager)->post('/sheq/exposure', [
            'period_year' => Carbon::now()->year,
            'period_month' => 1,
            'employee_hours' => 181_440,
            'contractor_hours' => 22_000,
            'source' => 'PAYROLL',
        ])->assertRedirect();

        $row = DB::table('sheq_exposure_hours')
            ->where('company_id', $this->companyId)
            ->where('period_year', Carbon::now()->year)->where('period_month', 1)
            ->first();

        $this->assertNotNull($row, 'The screen must write the row KpiService divides by.');
        $this->assertEquals(181_440, (float) $row->employee_hours);
        $this->assertSame('PAYROLL', $row->source);
        $this->assertSame($this->manager->id, (int) $row->captured_by);
    }

    public function test_every_month_of_the_year_so_far_is_listed_so_a_gap_is_visible(): void
    {
        $props = $this->actingAs($this->manager)->get('/sheq/exposure')
            ->viewData('page')['props'];

        // A month with no hours is the finding. Listing only the rows that exist hides exactly the
        // months whose rates cannot be computed.
        $this->assertCount(Carbon::now()->month, $props['rows']);
        $this->assertFalse($props['rows'][0]['captured']);
    }

    public function test_a_month_resting_on_a_keyed_figure_is_marked_estimated(): void
    {
        app(KpiService::class)->recordExposure($this->companyId, [
            'period_year' => 2024, 'period_month' => 3,
            'employee_hours' => 100_000, 'source' => 'MANUAL',
        ], $this->manager->id);

        $rows = collect($this->actingAs($this->manager)->get('/sheq/exposure?year=2024')
            ->viewData('page')['props']['rows']);

        $march = $rows->firstWhere('period_month', 3);

        $this->assertTrue($march['captured']);
        // The distinction the board pack turns on: a keyed figure and a payroll extract are not
        // the same evidence, and a rate computed from the first must say so.
        $this->assertTrue($march['estimated']);
    }

    public function test_a_payroll_extract_is_not_marked_estimated(): void
    {
        app(KpiService::class)->recordExposure($this->companyId, [
            'period_year' => 2023, 'period_month' => 5,
            'employee_hours' => 100_000, 'source' => 'PAYROLL',
        ], $this->manager->id);

        $rows = collect($this->actingAs($this->manager)->get('/sheq/exposure?year=2023')
            ->viewData('page')['props']['rows']);

        $this->assertFalse($rows->firstWhere('period_month', 5)['estimated']);
    }

    public function test_recording_the_same_month_twice_amends_rather_than_doubles(): void
    {
        foreach ([100_000, 120_000] as $hours) {
            $this->actingAs($this->manager)->post('/sheq/exposure', [
                'period_year' => 2022, 'period_month' => 7,
                'employee_hours' => $hours, 'source' => 'PAYROLL',
            ])->assertRedirect();
        }

        $rows = DB::table('sheq_exposure_hours')
            ->where('company_id', $this->companyId)
            ->where('period_year', 2022)->where('period_month', 7)->get();

        // Two rows for one month would double the denominator and halve every rate.
        $this->assertCount(1, $rows);
        $this->assertEquals(120_000, (float) $rows->first()->employee_hours);
    }

    public function test_hours_feed_the_injury_rates(): void
    {
        $this->actingAs($this->manager)->post('/sheq/exposure', [
            'period_year' => 2021, 'period_month' => 1,
            'employee_hours' => 200_000, 'source' => 'PAYROLL',
        ])->assertRedirect();

        $period = app(KpiService::class)->computePeriod($this->companyId, 2021, 1);

        // The point of the whole screen: the figure the rates divide by is now the one captured,
        // and the period is no longer flagged as resting on an estimate.
        $this->assertEquals(200_000, (float) $period['inputs']['exposure']['total_hours']);
        $this->assertFalse($period['exposure_estimated']);
    }

    public function test_hours_for_another_tenants_site_are_refused(): void
    {
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'exp-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignSite = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'code' => 'F'.Str::random(6), 'name' => 'Theirs', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // `recordExposure` keys on site_id, so an unchecked id stamps this company's hours against
        // somebody else's site — and the rates for both come out wrong.
        $this->actingAs($this->manager)->post('/sheq/exposure', [
            'site_id' => $foreignSite,
            'period_year' => 2021, 'period_month' => 2,
            'employee_hours' => 1_000, 'source' => 'PAYROLL',
        ])->assertSessionHasErrors('site_id');

        $this->assertDatabaseMissing('sheq_exposure_hours', [
            'company_id' => $this->companyId, 'site_id' => $foreignSite,
        ]);
    }

    public function test_this_tenants_own_site_is_accepted(): void
    {
        $site = $this->siteId();

        $this->actingAs($this->manager)->post('/sheq/exposure', [
            'site_id' => $site, 'period_year' => 2021, 'period_month' => 3,
            'employee_hours' => 5_000, 'source' => 'PAYROLL',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sheq_exposure_hours', [
            'company_id' => $this->companyId, 'site_id' => $site, 'period_month' => 3,
        ]);
    }

    public function test_recording_hours_needs_the_configure_entitlement(): void
    {
        $this->actingAs($this->onlooker)->post('/sheq/exposure', [
            'period_year' => 2021, 'period_month' => 4,
            'employee_hours' => 1, 'source' => 'PAYROLL',
        ])->assertForbidden();
    }

    public function test_zero_hours_is_a_real_answer_and_a_missing_figure_is_not(): void
    {
        // A site shut for the month worked no hours. That is different from nobody telling us, and
        // the screen must be able to say the first.
        $this->actingAs($this->manager)->post('/sheq/exposure', [
            'period_year' => 2021, 'period_month' => 5,
            'employee_hours' => 0, 'source' => 'PAYROLL',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post('/sheq/exposure', [
            'period_year' => 2021, 'period_month' => 6, 'source' => 'PAYROLL',
        ])->assertSessionHasErrors('employee_hours');
    }
}
