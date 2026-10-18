<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Support\LabourRates;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Maintaining the labour-law figures.
 *
 * The minimum wage and the BCEA multipliers were moved out of PHP constants into rows so a March
 * rate change would be data entry rather than a deploy. That is only true if there is somewhere to
 * enter it — until this screen existed the only way to raise the minimum wage was a SQL client.
 */
class LabourRateAdminTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $admin;

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

        // The routes sit behind module.active, so the module has to be switched on for this
        // installation before any of its screens answer.
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['payroll', 'payroll_bureau'] as $code) {
            DB::table('security_modules')->updateOrInsert(
                ['code_name' => $code],
                ['uuid' => (string) Str::uuid(), 'name' => $code, 'category_id' => $categoryId,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        Cache::flush();

        $this->admin = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'rates+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->admin);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'country' => 'ZA', 'sector' => null,
            'effective_from' => '2027-03-01',
            'nmw_hourly' => 30.50, 'standard_monthly_hours' => 160,
            'max_overtime_hours_per_week' => 10,
            'overtime_multiplier' => 1.5, 'sunday_multiplier' => 2, 'public_holiday_multiplier' => 2,
            'coida_earnings_ceiling' => 620000, 'source' => 'Government Gazette',
        ], $overrides);
    }

    // ── The screen ───────────────────────────────────────────────────────────

    public function test_the_screen_shows_every_determination_and_what_is_in_force(): void
    {
        $this->post(route('payroll.labour-rates.store'), $this->payload())->assertRedirect();

        $this->get(route('payroll.labour-rates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/HR/Payroll/LabourRates/Index')
                ->has('rates')
                ->has('current'));
    }

    public function test_the_monthly_wage_is_derived_rather_than_captured_twice(): void
    {
        $this->post(route('payroll.labour-rates.store'), $this->payload([
            'nmw_hourly' => 30.00, 'standard_monthly_hours' => 160,
        ]))->assertRedirect();

        $row = collect($this->get(route('payroll.labour-rates.index'))
            ->viewData('page')['props']['rates'])
            ->firstWhere('effective_from', '2027-03-01');

        // Two figures that must agree cannot both be captured — one of them will be wrong.
        $this->assertSame(4800.0, $row['nmw_monthly']);
    }

    // ── One rate per day ─────────────────────────────────────────────────────

    public function test_capturing_a_new_determination_closes_the_one_it_replaces(): void
    {
        $this->post(route('payroll.labour-rates.store'), $this->payload([
            'effective_from' => '2026-03-01', 'nmw_hourly' => 28.79,
        ]))->assertRedirect();

        $this->post(route('payroll.labour-rates.store'), $this->payload([
            'effective_from' => '2027-03-01', 'nmw_hourly' => 30.50,
        ]))->assertRedirect();

        $closed = DB::table('sa_labour_rates')
            ->where('country', 'ZA')->whereNull('sector')
            ->whereDate('effective_from', '2026-03-01')->first();

        // The day before, not the same day: two rows in force on one date makes "which rate applies"
        // a matter of sort order.
        $this->assertSame('2027-02-28', substr((string) $closed->effective_to, 0, 10));
    }

    public function test_a_rate_change_is_visible_immediately_rather_than_after_the_cache_expires(): void
    {
        $this->post(route('payroll.labour-rates.store'), $this->payload([
            'effective_from' => '2027-03-01', 'nmw_hourly' => 30.50,
        ]))->assertRedirect();

        $before = app(LabourRates::class)->on('2027-06-30');
        $this->assertEqualsWithDelta(30.50, (float) $before->nmw_hourly, 0.001);

        $this->post(route('payroll.labour-rates.store'), $this->payload([
            'effective_from' => '2027-03-01', 'nmw_hourly' => 32.00,
        ]))->assertRedirect();

        // The key carries the date, so there is no fixed set of keys to forget. A correction typed
        // in the morning must not be answered with the morning's figure all afternoon.
        $after = app(LabourRates::class)->on('2027-06-30');
        $this->assertEqualsWithDelta(32.00, (float) $after->nmw_hourly, 0.001);
    }

    public function test_a_sectoral_determination_does_not_close_the_general_one(): void
    {
        $this->post(route('payroll.labour-rates.store'), $this->payload([
            'effective_from' => '2026-03-01',
        ]))->assertRedirect();

        $this->post(route('payroll.labour-rates.store'), $this->payload([
            'effective_from' => '2027-03-01', 'sector' => 'farm', 'nmw_hourly' => 27.58,
        ]))->assertRedirect();

        $general = DB::table('sa_labour_rates')
            ->where('country', 'ZA')->whereNull('sector')
            ->whereDate('effective_from', '2026-03-01')->first();

        $this->assertNull($general->effective_to);
        $this->assertEqualsWithDelta(27.58, (float) app(LabourRates::class)->on('2027-06-30', 'farm')->nmw_hourly, 0.001);
    }

    // ── What may not be captured ─────────────────────────────────────────────

    public function test_a_multiplier_below_one_is_refused(): void
    {
        // Below 1× is not a discount, it is paying less for overtime than for ordinary time, which
        // BCEA §10 does not allow — and a typo here quietly underpays everybody.
        $this->post(route('payroll.labour-rates.store'), $this->payload(['overtime_multiplier' => 0.5]))
            ->assertSessionHasErrors('overtime_multiplier');
    }

    public function test_a_determination_without_a_start_date_is_refused(): void
    {
        $this->post(route('payroll.labour-rates.store'), $this->payload(['effective_from' => null]))
            ->assertSessionHasErrors('effective_from');
    }
}
