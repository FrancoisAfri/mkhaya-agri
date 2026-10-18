<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * MFG-025: areas outside the company's package are refused by URL and by direct call; role
 * permissions still apply inside a package; the factory cap holds; an overdue account is read-only;
 * and taking the module away hides the history without deleting any of it.
 */
class PackagingTest extends ManufacturingTestCase
{
    private $boss;

    private $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $all = Role::firstOrCreate(['name' => 'mfg test package owner', 'guard_name' => 'web']);
        $all->syncPermissions(RoleCatalog::all());
        $view = Role::firstOrCreate(['name' => 'mfg test package viewer', 'guard_name' => 'web']);
        $view->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_PLANNING]);
        $this->boss = $this->makeUser('pkg-owner@example.test', $all);
        $this->viewer = $this->makeUser('pkg-viewer@example.test', $view);
        $this->grantModuleTier($this->boss);
        $this->grantModuleTier($this->viewer);
    }

    /** The company's own module list, so the plan no longer includes everything. */
    private function sell(array $codes): void
    {
        DB::table('company_modules')->where('company_id', $this->company->id)->delete();
        foreach ($codes as $code) {
            DB::table('company_modules')->insert(['company_id' => $this->company->id, 'module_code' => $code, 'source' => 'addon', 'price_monthly' => 0,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_an_area_outside_the_package_is_refused_by_url_and_by_direct_call(): void
    {
        config(['manufacturing.default_package' => 'ESSENTIALS']);
        $this->sell(['manufacturing']);

        $this->actingAs($this->boss)->get(route('manufacturing.production.orders.index'))->assertOk();
        $this->actingAs($this->boss)->get(route('manufacturing.planning.mrp'))->assertRedirect(route('manufacturing.dashboard'));
        $this->actingAs($this->boss)->getJson(route('manufacturing.planning.mrp'))->assertForbidden();
        $this->actingAs($this->boss)->postJson(route('manufacturing.factories.transfers.store'), [])->assertForbidden();

        $this->sell(['manufacturing', 'manufacturing_professional']);
        $this->actingAs($this->boss)->get(route('manufacturing.planning.mrp'))->assertOk();
        $this->actingAs($this->boss)->get(route('manufacturing.factories.transfers.index'))->assertRedirect(route('manufacturing.dashboard'));

        // The package opens the area; the role still decides who may use it.
        $this->actingAs($this->viewer)->get(route('manufacturing.costing.standards.index'))->assertForbidden();

        $this->sell(['manufacturing', 'manufacturing_enterprise']);
        $this->actingAs($this->boss)->get(route('manufacturing.factories.transfers.index'))->assertOk();
    }

    public function test_the_factory_cap_and_the_default_for_existing_customers(): void
    {
        // Nothing sold separately: the default package, Enterprise, keeps every area open.
        $this->sell(['manufacturing']);
        $this->actingAs($this->boss)->get(route('manufacturing.factories.network'))->assertOk();

        config(['manufacturing.default_package' => 'ESSENTIALS']);
        $this->factory('ONE');
        $this->actingAs($this->boss)->post(route('manufacturing.setup.factories.store'), ['code' => 'TWO', 'name' => 'Second plant', 'timezone' => 'Africa/Johannesburg',
            'currency' => 'ZAR', 'measurement_system' => 'METRIC'])->assertSessionHas('error');
        $this->assertSame(1, $this->asCompany($this->company, fn () => Factory::query()->count()));
    }

    public function test_an_overdue_account_is_read_only_and_losing_the_module_keeps_the_history(): void
    {
        $plant = $this->factory('PLT');
        $this->asCompany($this->company, fn () => \App\Modules\Operations\Manufacturing\Models\FactoryPolicy::for($plant->id, $this->company->id)->update(['require_routing' => false]));
        $item = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($item, [[$this->stockItem('STEEL', 'kg', 10), 2, 0, 'kg']]);
        $order = $this->asCompany($this->company, fn () => app(ProductionOrders::class)->create(['item' => $item, 'quantity' => 2,
            'due_date' => now()->addDay()->toDateString(), 'factory' => $plant, 'warehouse' => $this->warehouse('W1')], $this->boss));

        $invoice = DB::table('subscription_invoices')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id,
            'period' => now()->format('Y-m'), 'amount' => 1000, 'status' => 'overdue', 'due_date' => now()->subDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->boss)->get(route('manufacturing.production.orders.show', $order->uuid))->assertOk();
        $this->actingAs($this->boss)->get(route('manufacturing.reports.executive', ['export' => 'csv']))->assertOk();
        $this->actingAs($this->boss)->post(route('manufacturing.production.orders.plan', $order->uuid))->assertRedirect(route('manufacturing.dashboard'));
        $this->assertSame(PO::DRAFT, $order->fresh()->status, 'Nothing changed while overdue.');

        DB::table('subscription_invoices')->where('id', $invoice)->update(['status' => 'paid', 'paid_at' => now()]);
        $this->actingAs($this->boss)->post(route('manufacturing.production.orders.plan', $order->uuid))->assertSessionHas('success');

        // The module is taken away: the screens close, the records stay.
        $this->sell(['dms']);
        $this->actingAs($this->boss)->get(route('manufacturing.production.orders.show', $order->uuid))->assertRedirect();
        $this->assertSame(1, DB::table('mfg_production_orders')->where('id', $order->id)->count());
        $this->assertTrue(DB::table('activity_log')->where('subject_type', PO::class)->where('subject_id', $order->id)->exists());
        $this->sell(['manufacturing']);
        $this->actingAs($this->boss)->get(route('manufacturing.production.orders.show', $order->uuid))->assertOk();
    }
}
