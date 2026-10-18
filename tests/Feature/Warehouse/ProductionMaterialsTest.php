<?php

namespace Tests\Feature\Warehouse;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Services\ProductionMaterials;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog as Wms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Manufacturing\ManufacturingTestCase;

/**
 * WMS-160..165: the warehouse reads Manufacturing's own production orders -- what each needs, what
 * is short, what was over-used -- raises the pick, puts finished goods away, and shows a lot's
 * genealogy. No production data is copied into warehouse tables.
 *
 * A WIDGET is 2 kg of STEEL. Ten widgets need 20 kg; 12 kg is on the shelf.
 */
class ProductionMaterialsTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private FinStockItem $steel;

    private $wms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $categoryId = DB::table('security_categories')->where('name', 'Operations & Support')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Operations & Support', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(['code_name' => 'warehouse_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Warehouse Management & Intelligence', 'category_id' => $categoryId, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('module_active_warehouse_management');
        Cache::forget('hub_module_id:warehouse_management');
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => 'warehouse_management'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach (Wms::all() as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'wms test production', 'guard_name' => 'web']);
        $role->syncPermissions(Wms::all());
        $this->wms = $this->makeUser('wms-production@example.test', $role);
        DB::table('security_module_access')->updateOrInsert(
            ['module_id' => DB::table('security_modules')->where('code_name', 'warehouse_management')->value('id'), 'user_id' => $this->wms->id],
            ['active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, function () {
            $this->store->update(['is_wms_managed' => true]);
            Shift::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day', 'starts_at' => '06:00', 'ends_at' => '14:00',
                'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7], 'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true]);
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_qc_before_putaway' => false, 'require_material_for_approval' => false,
                'require_routing' => false, 'allow_over_issue' => true]);
        });

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->widget = $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $this->stockItem('WIDGET', 'ea', 0)->id, 'kind' => 'FINISHED',
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true, 'is_lot_tracked' => true,
        ]));
        $this->approvedBom($this->widget, [[$this->steel, 2, 0, 'kg']]);
    }

    private function lot(string $number, float $qty): FinStockLot
    {
        return $this->asCompany($this->company, function () use ($number, $qty) {
            $lot = app(StockLedger::class)->lot($this->steel->fresh(), $number, ['origin' => 'RECEIPT']);
            app(StockLedger::class)->receive($this->steel->fresh(), $qty, 10, $this->store, ['lot_id' => $lot->id, 'reference' => "GRN {$number}"]);

            return $lot;
        });
    }

    private function order(float $qty): PO
    {
        return $this->asCompany($this->company, function () use ($qty) {
            $order = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => $qty, 'due_date' => now()->addDays(5)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            app(ProductionOrderLifecycle::class)->plan($order, $this->engineer);
            app(ProductionOrderLifecycle::class)->approve($order, $this->approver);

            return $order->fresh();
        });
    }

    private function board(): array
    {
        return $this->asCompany($this->company, fn () => app(ProductionMaterials::class)->board());
    }

    public function test_the_board_shows_what_is_short_before_release_and_the_pick_is_raised_once(): void
    {
        $this->lot('S-1', 12);
        $order = $this->order(10);

        $row = collect($this->board()['orders'])->firstWhere('number', $order->number);
        $line = $row['lines'][0];
        $this->assertSame(20.0, $line['still_needed']);
        $this->assertSame(12.0, $line['free']);
        $this->assertSame(8.0, $line['short']);
        $this->assertFalse($row['can_release']);
        $this->assertNull($row['warehouse_order']);

        $this->actingAs($this->viewerWithout())->post(route('warehouse.production.pick', $order->uuid))->assertForbidden();
        $this->actingAs($this->wms)->post(route('warehouse.production.pick', $order->uuid))->assertSessionHas('success');

        $this->assertDatabaseHas('wms_orders', ['source' => 'MANUFACTURING', 'source_id' => $order->id]);
        $this->assertNotNull(collect($this->board()['orders'])->firstWhere('number', $order->number)['warehouse_order']);
    }

    public function test_a_warehouse_not_run_by_the_module_is_refused_politely(): void
    {
        $this->asCompany($this->company, fn () => $this->store->update(['is_wms_managed' => false]));
        $order = $this->order(1);

        $this->actingAs($this->wms)->post(route('warehouse.production.pick', $order->uuid))->assertSessionHasErrors();
        $this->assertDatabaseMissing('wms_orders', ['source' => 'MANUFACTURING', 'source_id' => $order->id]);
    }

    public function test_over_issue_against_good_output_is_flagged_and_finished_goods_are_offered_for_put_away(): void
    {
        $lot = $this->lot('S-2', 50);
        $order = $this->order(10);
        $this->asCompany($this->company, function () use ($order, $lot) {
            app(ProductionOrderLifecycle::class)->release($order->fresh(), $this->approver);
            $floor = app(ProductionExecution::class);
            // 5 widgets should have used 10 kg; 13 kg went in.
            $floor->issue($order->fresh(), $order->components()->firstOrFail(), 13, $this->engineer, ['lot_id' => $lot->id]);
            $floor->receiveOutput($order->fresh(), 5, $this->engineer, ['lot_number' => 'W-1']);
        });

        $board = $this->board();
        $line = collect($board['orders'])->firstWhere('number', $order->number)['lines'][0];
        $this->assertSame(10.0, $line['expected_for_output']);
        $this->assertSame(13.0, $line['used']);
        $this->assertSame(30.0, $line['variance_pct']);
        $this->assertTrue($line['flag']);

        $waiting = collect($board['finished_awaiting_putaway'])->firstWhere('code', 'WIDGET');
        $this->assertNotNull($waiting);
        $this->actingAs($this->wms)->post(route('warehouse.production.putaway', $waiting['position']))->assertSessionHas('success');
        $this->assertSame(1, PutawayTask::withoutGlobalScopes()->where('company_id', $this->company->id)->where('source_type', 'PRODUCTION_ORDER')->count());
        $this->assertNull(collect($this->board()['finished_awaiting_putaway'])->firstWhere('code', 'WIDGET'), 'an open task takes it off the list');

        // Genealogy: the widget lot was made from S-2, and S-2 went into it.
        $this->actingAs($this->wms)->get(route('warehouse.batches.show', FinStockLot::withoutGlobalScopes()->where('lot_number', 'W-1')->value('uuid')))
            ->assertOk()->assertInertia(fn ($p) => $p->where('genealogy.made_from.0.number', 'S-2'));
        $this->actingAs($this->wms)->get(route('warehouse.batches.show', $lot->uuid))
            ->assertOk()->assertInertia(fn ($p) => $p->where('genealogy.went_into.0.number', 'W-1'));

        $this->actingAs($this->wms)->get(route('warehouse.production.board'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Production/Materials')->has('orders')->where('can.orders', true));
    }

    public function test_finished_goods_accepted_at_final_inspection_get_a_put_away_task(): void
    {
        $lot = $this->lot('S-3', 10);
        $order = $this->order(2);
        $this->asCompany($this->company, function () use ($order, $lot) {
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_qc_before_putaway' => true]);
            app(ProductionOrderLifecycle::class)->release($order->fresh(), $this->approver);
            $floor = app(ProductionExecution::class);
            $floor->issue($order->fresh(), $order->components()->firstOrFail(), 4, $this->engineer, ['lot_id' => $lot->id]);
            $floor->receiveOutput($order->fresh(), 2, $this->engineer, ['lot_number' => 'W-2']);

            $inspection = Inspection::query()->where('production_order_id', $order->id)->where('stage', 'FINAL')->firstOrFail();
            $this->assertSame(0, PutawayTask::query()->where('source_type', 'PRODUCTION_ORDER')->count(), 'nothing is put away while it is held');
            $inspection = app(QualityControl::class)->record($inspection, [['name' => 'Visual', 'passed' => true]], $this->approver);
            app(QualityControl::class)->dispose($inspection, Inspection::ACCEPT, null, $this->approver);

            $task = PutawayTask::query()->where('source_type', 'PRODUCTION_ORDER')->where('source_id', $order->id)->sole();
            $this->assertSame(2.0, (float) $task->qty);
            $this->assertSame($inspection->lot_id, $task->lot_id);
        });
    }

    private function viewerWithout()
    {
        $role = Role::firstOrCreate(['name' => 'wms test production viewer', 'guard_name' => 'web']);
        $role->syncPermissions([Wms::VIEW]);
        $user = $this->makeUser('wms-production-viewer@example.test', $role);
        DB::table('security_module_access')->updateOrInsert(
            ['module_id' => DB::table('security_modules')->where('code_name', 'warehouse_management')->value('id'), 'user_id' => $user->id],
            ['active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }
}
