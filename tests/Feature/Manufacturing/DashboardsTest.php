<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * MFG-018: each dashboard reads the module's own records, takes the shared filters, is shown only
 * to the roles its figures belong to, and exports the same figures under the same authorisation.
 */
class DashboardsTest extends ManufacturingTestCase
{
    private Factory $plant;

    private Factory $other;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $frame;

    private $director;

    private $scheduler;

    private $fitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $director = Role::firstOrCreate(['name' => 'mfg test director', 'guard_name' => 'web']);
        $director->syncPermissions(RoleCatalog::all());
        $scheduler = Role::firstOrCreate(['name' => 'mfg test scheduler', 'guard_name' => 'web']);
        $scheduler->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_PLANNING]);
        $fitter = Role::firstOrCreate(['name' => 'mfg test fitter', 'guard_name' => 'web']);
        $fitter->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_MAINTENANCE]);
        foreach (['director' => $director, 'scheduler' => $scheduler, 'fitter' => $fitter] as $name => $role) {
            $this->{$name} = $this->makeUser("{$name}-dash@example.test", $role);
            $this->grantModuleTier($this->{$name});
        }

        $this->plant = $this->factory('PLT');
        $this->other = $this->factory('OTH');
        $this->store = $this->warehouse('STORE');
        foreach ([$this->plant, $this->other] as $f) {
            $this->asCompany($this->company, fn () => FactoryPolicy::for($f->id, $this->company->id)
                ->update(['require_material_for_approval' => false, 'require_routing' => false, 'require_qc_before_putaway' => false]));
        }
        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$this->steel, 2, 0, 'kg']]);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->steel->fresh(), 500, 10, $this->store, ['reference' => 'GRN']));
    }

    private function order(Factory $factory, float $qty, string $due, bool $produce): PO
    {
        return $this->asCompany($this->company, function () use ($factory, $qty, $due, $produce) {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => $qty, 'due_date' => $due,
                'factory' => $factory, 'warehouse' => $this->store], $this->director);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->director);
            $life->approve($o->fresh(), $this->director);
            $life->release($o->fresh(), $this->director);
            if ($produce) {
                $life->start($o->fresh(), $this->director);
                $exec = app(ProductionExecution::class);
                $exec->issue($o->fresh(), $o->components()->firstOrFail(), $qty * 2, $this->director);
                $exec->receiveOutput($o->fresh(), $qty, $this->director);
            }

            return $o->fresh();
        });
    }

    private function kpi(array $page, string $key)
    {
        return collect($page['props']['data']['kpis'])->firstWhere('key', $key)['value'] ?? null;
    }

    public function test_the_executive_dashboard_reads_the_period_and_honours_the_factory_filter(): void
    {
        $this->order($this->plant, 8, now()->addDays(5)->toDateString(), true);
        $this->order($this->other, 3, now()->addDays(5)->toDateString(), true);
        $this->order($this->plant, 4, now()->subDays(2)->toDateString(), false);   // overdue, nothing made

        $centre = $this->asCompany($this->company, fn () => WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'WLD', 'name' => 'Weld', 'cost_per_hour' => 100, 'is_active' => true, 'concurrent_jobs' => 1]));
        $reason = $this->asCompany($this->company, fn () => DowntimeReason::query()->where('code', 'BRK')->first()
            ?? DowntimeReason::create(['company_id' => $this->company->id, 'code' => 'BRK', 'name' => 'Breakdown', 'category' => 'BREAKDOWN', 'is_planned' => false, 'is_active' => true]));
        $this->asCompany($this->company, fn () => DowntimeEvent::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'work_centre_id' => $centre->id,
            'reason_id' => $reason->id, 'started_at' => now()->subHours(5), 'ended_at' => now()->subHours(2), 'minutes' => 180]));

        $all = $this->actingAs($this->director)->get(route('manufacturing.reports.executive'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Reports/Dashboard', $all['component']);
        $this->assertEquals(11.0, $this->kpi($all, 'output_qty'));
        $this->assertSame(1, $this->kpi($all, 'overdue_orders'));
        $this->assertEquals(3.0, $this->kpi($all, 'unplanned_downtime_hours'));
        $this->assertEqualsCanonicalizing(['executive', 'planning', 'inventory', 'costs', 'quality'], $all['props']['available']);

        $plant = $this->actingAs($this->director)->get(route('manufacturing.reports.executive', ['factory_id' => $this->plant->id]))->assertOk()->viewData('page');
        $this->assertEquals(8.0, $this->kpi($plant, 'output_qty'));

        // A period before any of it shows nothing.
        $before = $this->actingAs($this->director)->get(route('manufacturing.reports.executive', ['from' => now()->subYear()->toDateString(), 'to' => now()->subMonths(6)->toDateString()]))->viewData('page');
        $this->assertEquals(0.0, $this->kpi($before, 'output_qty'));
    }

    public function test_planning_inventory_and_cost_dashboards(): void
    {
        $this->order($this->plant, 5, now()->subDay()->toDateString(), false);           // late
        $active = $this->order($this->plant, 6, now()->addDays(5)->toDateString(), true); // work in progress
        $this->asCompany($this->company, function () {
            $ledger = app(StockLedger::class);
            $glue = $this->stockItem('GLUE', 'l', 4);
            $lot = $ledger->lot($glue, 'G-1', ['expires_on' => now()->addDays(10)->toDateString()]);
            $ledger->receive($glue->fresh(), 10, 4, $this->store, ['reference' => 'GRN-G', 'lot_id' => $lot->id]);
        });

        $planning = $this->actingAs($this->scheduler)->get(route('manufacturing.reports.planning'))->assertOk()->viewData('page');
        $this->assertSame(1, $this->kpi($planning, 'late_orders'));
        $this->assertSame(['planning'], $planning['props']['available']);

        $inventory = $this->actingAs($this->director)->get(route('manufacturing.reports.inventory'))->assertOk()->viewData('page');
        $this->assertSame(1, $this->kpi($inventory, 'expiring_lots'));
        // 12 kg of steel issued at 10, less 6 frames received at the standard material cost of 20 each: nothing left in WIP.
        $this->assertEquals(0.0, $this->kpi($inventory, 'wip_value'));
        $this->assertSame('G-1', $inventory['props']['data']['tables']['expiry_risk'][0]['lot']);

        $this->actingAs($this->director)->get(route('manufacturing.reports.costs', ['item_id' => $this->frame->id, 'status' => 'IN_PRODUCTION']))->assertOk();
        $this->actingAs($this->scheduler)->get(route('manufacturing.reports.costs'))->assertForbidden();
        $this->actingAs($this->scheduler)->get(route('manufacturing.reports.inventory'))->assertForbidden();
    }

    public function test_the_quality_and_maintenance_dashboard_shows_only_the_half_a_role_may_see(): void
    {
        $this->asCompany($this->company, fn () => Inspection::create(['company_id' => $this->company->id, 'number' => 'QI-D1', 'stage' => 'FINAL',
            'stock_item_id' => $this->steel->id, 'qty' => 1, 'result' => Inspection::FAILED, 'status' => Inspection::CLOSED, 'inspected_at' => now()->subDay()]));

        $full = $this->actingAs($this->director)->get(route('manufacturing.reports.quality'))->assertOk()->viewData('page');
        $this->assertEquals(0.0, $this->kpi($full, 'first_pass_yield'));
        $this->assertNotNull(collect($full['props']['data']['kpis'])->firstWhere('key', 'overdue_maintenance'));

        $fitter = $this->actingAs($this->fitter)->get(route('manufacturing.reports.quality'))->assertOk()->viewData('page');
        $keys = array_column($fitter['props']['data']['kpis'], 'key');
        $this->assertContains('equipment_availability', $keys);
        $this->assertNotContains('first_pass_yield', $keys);
        $this->assertArrayNotHasKey('defect_trend', $fitter['props']['data']['tables']);
    }

    public function test_exports_carry_the_same_figures_under_the_same_permission_and_are_logged(): void
    {
        $this->order($this->plant, 4, now()->addDays(3)->toDateString(), true);

        $csv = $this->actingAs($this->director)->get(route('manufacturing.reports.executive', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('kpi,output_qty,4', $csv);
        $this->assertStringContainsString('output_by_product,product,qty,value', $csv);
        $this->assertTrue(DB::table('activity_log')->where('description', 'manufacturing.dashboard.exported')->where('causer_id', $this->director->id)->exists());

        $this->actingAs($this->scheduler)->get(route('manufacturing.reports.executive', ['export' => 'csv']))->assertForbidden();
        $this->actingAs($this->outsider)->get(route('manufacturing.reports.planning'))->assertForbidden();

        // Each role's hub shows only its dashboards.
        $cards = array_column($this->actingAs($this->scheduler)->get('/manufacturing/quality')->assertOk()->viewData('page')['props']['cards'], 'path');
        $this->assertSame(['/manufacturing/reports/planning'], array_values($cards));
    }
}
