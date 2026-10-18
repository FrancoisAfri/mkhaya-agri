<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Http\Controllers\SheqDashboardController;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The SHEQ landing page (SHEQ-DASH-1, SHEQ-DASH-2).
 *
 * The wiring test proves the controller can be constructed; this one proves it can actually run.
 * The dashboard issues a dozen aggregate queries against tables owned by other parts of the module,
 * so a renamed column would only surface on the page itself — the most visible place to break.
 *
 * The behaviours asserted below are the ones where being wrong is worse than being blank: never
 * reporting a rate without exposure hours, never counting another tenant's rows, and never
 * computing the KPI projection inside a page request.
 */
class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
    }

    /**
     * Render through the container, exactly as an Inertia page visit does.
     *
     * The `X-Inertia` header matters: without it Inertia returns a full HTML document, which renders
     * `app.blade.php`, which needs a compiled Vite manifest. That made a test about controller data
     * fail whenever `public/build` was empty — asserting on the asset pipeline by accident. With the
     * header the response is the JSON payload, which is the thing under test.
     */
    private function render(?int $companyId = null): array
    {
        $request = Request::create('/sheq', 'GET');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Version', '');

        if ($companyId !== null) {
            // The controller resolves the tenant through ResolvesCompany; the session variable is
            // what a real request carries.
            app(CurrentCompany::class)->set($companyId);
        }

        $response = app(SheqDashboardController::class)->index($request);

        $payload = json_decode($response->toResponse($request)->getContent(), true);

        return $payload['props'] ?? [];
    }

    public function test_the_dashboard_renders_every_panel_without_a_query_error(): void
    {
        // A dozen aggregates over tables this controller does not own. A column rename elsewhere
        // breaks the landing page, and this is the cheapest place to find out.
        $props = $this->render($this->companyId);

        foreach (['attention', 'leading', 'recentIncidents', 'period'] as $key) {
            $this->assertArrayHasKey($key, $props, "The dashboard did not return {$key}.");
        }

        $this->assertNotEmpty($props['attention']);
        $this->assertNotEmpty($props['leading']);
    }

    public function test_every_attention_tile_carries_a_count_and_a_destination(): void
    {
        // A tile that says "3 overdue" without saying where to go is a dead end.
        $props = $this->render($this->companyId);

        foreach ($props['attention'] as $tile) {
            $this->assertArrayHasKey('key', $tile);
            $this->assertIsInt($tile['count']);
            $this->assertNotEmpty($tile['href'], "Tile {$tile['key']} has nowhere to go.");
        }
    }

    public function test_the_dashboard_does_not_compute_the_kpi_projection(): void
    {
        /**
         * SHEQ-ARC-4: dashboards read the projection, they do not build it. Computing in-request
         * would write a snapshot row on every page view and hand a different figure to whoever
         * loaded the page last. Counting the rows either side is the only way to prove it.
         */
        $before = DB::table('sheq_kpi_snapshots')->where('company_id', $this->companyId)->count();

        $this->render($this->companyId);

        $this->assertSame($before,
            DB::table('sheq_kpi_snapshots')->where('company_id', $this->companyId)->count(),
            'Rendering the dashboard wrote a KPI snapshot. It must read the projection, not build it.');
    }

    public function test_lagging_kpis_are_null_until_the_projection_has_been_built(): void
    {
        // Null renders as "not computed yet", which is a different statement from "no injuries".
        DB::table('sheq_kpi_snapshots')->where('company_id', $this->companyId)->delete();

        $this->assertNull($this->render($this->companyId)['lagging']);
    }

    public function test_a_snapshot_with_no_exposure_hours_is_reported_as_unknown_not_zero(): void
    {
        /**
         * The most dangerous number this page could print is a green 0.0 LTIFR produced because
         * nobody captured hours worked. `has_exposure` is what stops the page showing rates at all.
         */
        $now = Carbon::now();

        DB::table('sheq_kpi_snapshots')->updateOrInsert(
            [
                'company_id' => $this->companyId, 'site_id' => null,
                'period_year' => (int) $now->year, 'period_month' => (int) $now->month,
            ],
            [
                'metrics' => json_encode(['rates' => [], 'lagging' => [], 'leading' => []]),
                'inputs' => json_encode(['exposure' => ['total_hours' => 0]]),
                'exposure_estimated' => false,
                'computed_at' => DB::raw('now()'),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        $lagging = $this->render($this->companyId)['lagging'];

        $this->assertNotNull($lagging);
        // The assertion that matters: no exposure means the page shows no rates at all.
        $this->assertFalse($lagging['has_exposure']);
        // Numeric, not identical: json_encode(0.0) emits `0`, so the float arrives as an int. That
        // is an encoding artefact of the payload, not a change in what the figure means.
        $this->assertEquals(0, $lagging['hours_worked']);
    }

    public function test_the_dashboard_counts_nothing_when_no_company_is_active(): void
    {
        /*
         * A SaaS rule, so it is asserted against a SaaS edition.
         *
         * A platform admin there has no tenant, and unscoped aggregates would total every tenant's
         * rows and present them as one company's. On a self-hosted installation there is exactly
         * one company and ResolvesTenant deliberately resolves to it — its own docblock calls
         * refusing to "pedantry rather than safety" — so `noCompany` is correctly false and this
         * assertion would be testing the opposite of the intended behaviour.
         */
        config(['edition.edition' => 'saas']);

        app(CurrentCompany::class)->set(null);

        $props = $this->render();

        $this->assertTrue($props['noCompany']);
        $this->assertSame([], $props['attention']);
        $this->assertNull($props['lagging']);
    }

    /**
     * The other half of the same rule, and the reason the one above has to name its edition.
     *
     * A self-hosted administrator has no CurrentCompany and usually no `users.company_id` either.
     * Treating that as "no tenant" would strand them on an empty dashboard on an installation that
     * has exactly one company to show them.
     */
    public function test_a_self_hosted_dashboard_falls_back_to_the_only_company(): void
    {
        config(['edition.edition' => 'self_hosted']);

        // No whereNull('deleted_at'): the companies table does not soft-delete, and SoleCompany
        // guards that with Schema::hasColumn for the same reason.
        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('Sole-company resolution needs exactly one company; this database has more.');
        }

        app(CurrentCompany::class)->set(null);

        $this->assertFalse($this->render()['noCompany'],
            'A self-hosted dashboard reported no company on an installation that has exactly one.');
    }

    public function test_another_tenants_overdue_work_is_not_counted(): void
    {
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-dash-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $baseline = collect($this->render($this->companyId)['attention'])
            ->firstWhere('key', 'hazards_untriaged')['count'];

        DB::table('sheq_hazards')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'reference' => 'HAZ-OTHER-'.Str::random(5),
            'description' => 'Belongs to a different tenant entirely.',
            'status' => 'OPEN', 'reported_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($baseline,
            collect($this->render($this->companyId)['attention'])
                ->firstWhere('key', 'hazards_untriaged')['count'],
            "Another tenant's open hazard was counted on this company's dashboard.");
    }
}
