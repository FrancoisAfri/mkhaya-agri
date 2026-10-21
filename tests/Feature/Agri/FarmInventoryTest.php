<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Services\FarmInventory;
use App\Modules\Agri\Services\FarmProcurement;
use App\Modules\Agri\Services\FarmStores;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §5: farm stores, chemical controls, FEFO issues from lots, transfers and procurement,
 * ending with the section's acceptance test — stock received against a purchase order increases
 * available stock once, creates the right lots and reconciles with purchasing and finance.
 */
class FarmInventoryTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->openBooks();
    }

    private function supplier(string $name = 'Agri Supplies'): int
    {
        return $this->asCompany($this->company, fn () => (int) DB::table('suppliers')->insertGetId(['company_name' => $name, 'company_id' => $this->company->id,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]));
    }

    private function order(int $warehouseId, int $supplierId, FinStockItem $item, float $qty, float $price, string $status = 'approved'): PurchaseOrder
    {
        return $this->asCompany($this->company, function () use ($warehouseId, $supplierId, $item, $qty, $price, $status) {
            $po = PurchaseOrder::create(['company_id' => $this->company->id, 'po_number' => 'PO-'.random_int(1000, 9999), 'supplier_id' => $supplierId,
                'title' => "Buy {$item->name}", 'amount' => $qty * $price, 'currency' => 'ZAR', 'status' => $status, 'source' => 'manual',
                'received_status' => 'pending', 'warehouse_id' => $warehouseId, 'order_date' => today()->subDays(10)->toDateString(),
                'expected_delivery' => today()->subDays(2)->toDateString()]);
            $po->items()->create(['company_id' => $this->company->id, 'name' => $item->name, 'quantity' => $qty, 'unit_price' => $price,
                'line_total' => $qty * $price, 'stock_item_id' => $item->id]);

            return $po->fresh('items');
        });
    }

    public function test_acceptance_stock_received_against_a_purchase_order_lands_once_in_the_farm_store_with_its_lots_and_farm_dimensions(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $seed = $this->stockItem('SEED-X', 'bag', 3000);
        $store = $this->asCompany($this->company, fn () => app(FarmStores::class)->storeFor($farm));
        $po = $this->order($store->id, $this->supplier(), $seed, 50, 3100);
        $line = $po->items->first();
        $grn = fn (float $qty, string $lot, string $key) => $this->asCompany($this->company, fn () => app(PurchaseOrderService::class)->createGRN($po->fresh('items'),
            [['item_id' => $line->id, 'accepted_qty' => $qty, 'lot_number' => $lot, 'expires_on' => today()->addYear()->toDateString()]], $this->manager->id, null,
            ['idempotency_key' => $key]));

        // Thirty now, in lot A; sent twice by a double tap: received once.
        $grn(30, 'LOT-A', 'grn-1');
        $grn(30, 'LOT-A', 'grn-1');
        $ledger = app(StockLedger::class);
        $this->assertEqualsWithDelta(30.0, $ledger->available($seed->fresh(), $store->id), 0.001);
        $this->assertSame(1, DB::table('fin_stock_lots')->where('item_id', $seed->id)->where('lot_number', 'LOT-A')->count());
        $this->assertEqualsWithDelta(30.0, (float) $line->fresh()->received_qty, 0.001);

        // The receipt is posted once, at the order price, and its goods-received line carries the farm's dimensions.
        $dims = DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_farm' = ?", [(string) $farm->id])->whereRaw("dimensions->>'agri_category' = 'stock'")->get();
        $this->assertEqualsWithDelta(93000.0, (float) $dims->sum('credit'), 0.01);

        // The rest on order shows as a backorder on the farm's purchasing view.
        $orders = $this->asCompany($this->company, fn () => app(FarmProcurement::class)->orders($farm));
        $this->assertTrue($orders[0]['backordered']);
        $this->assertEquals(20, $orders[0]['lines'][0]['outstanding']);

        // Twenty more in lot B, which expires sooner: the next field use takes lot B first (FEFO).
        $this->asCompany($this->company, fn () => app(PurchaseOrderService::class)->createGRN($po->fresh('items'),
            [['item_id' => $line->id, 'accepted_qty' => 20, 'lot_number' => 'LOT-B', 'expires_on' => today()->addMonths(2)->toDateString()]], $this->manager->id, null, ['idempotency_key' => 'grn-2']));
        $plan = $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'field_id' => $this->field($farm)->id, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ', 'planned_area' => 40,
            'status' => 'planted', 'planned_planting_on' => today(), 'planned_harvest_on' => today()->addMonths(5), 'yield_unit' => 't']));
        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid, 'activity_type' => 'planting',
            'title' => 'Plant', 'planned_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $act = FieldActivity::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $act->uuid), ['stock_item_id' => $seed->id, 'quantity' => 25])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $act->uuid), ['action' => 'complete'])->assertSessionHasNoErrors();

        $issues = DB::table('fin_stock_movements as m')->join('fin_stock_lots as l', 'l.id', '=', 'm.lot_id')->where('m.source_type', 'agri_activity')
            ->orderBy('m.id')->get(['l.lot_number', 'm.qty']);
        $this->assertSame(['LOT-B', 'LOT-A'], $issues->pluck('lot_number')->all());
        $this->assertEquals([-20.0, -5.0], $issues->pluck('qty')->map(fn ($q) => (float) $q)->all());
        $this->assertEqualsWithDelta(25 * 3100.0, (float) ActivityInput::withoutGlobalScopes()->sole()->total_cost, 0.01);
        $this->assertEqualsWithDelta(25.0, $ledger->available($seed->fresh(), $store->id), 0.001);
    }

    public function test_chemicals_are_stored_only_where_allowed_and_used_only_by_authorised_people(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $main = $this->asCompany($this->company, fn () => app(FarmStores::class)->storeFor($farm));
        $poison = $this->stockItem('PARAQUAT', 'l', 120);
        $oxidiser = $this->stockItem('CA-NITRATE', 'kg', 15);
        $this->asCompany($this->company, function () use ($poison, $oxidiser) {
            ChemicalProduct::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'stock_item_id' => $poison->id, 'registration_no' => 'L1234',
                'storage_class' => 'toxic', 'incompatible_with' => ['oxidiser'], 'restricted' => true, 'authorised_user_ids' => [$this->manager->id]]);
            ChemicalProduct::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'stock_item_id' => $oxidiser->id, 'storage_class' => 'oxidiser']);
        });

        // The main store holds no chemicals: a delivery of paraquat into it is refused.
        $this->actingAs($this->manager)->post(route('agri.stock.receive'), ['farm_id' => $farm->uuid, 'stock_item_id' => $poison->id, 'quantity' => 20, 'unit_cost' => 120,
            'received_on' => today()->toDateString(), 'basis' => 'purchase'])->assertSessionHasErrors('store');

        // A chemical store set up for toxic products takes it; the oxidiser may not join it.
        $this->actingAs($this->manager)->post(route('agri.stock.stores.store'), ['farm_id' => $farm->uuid, 'kind' => 'chemical_store', 'name' => 'Chem store',
            'restricted' => true, 'allowed_storage_classes' => ['toxic', 'oxidiser']])->assertSessionHasNoErrors();
        $chem = FarmStore::withoutGlobalScopes()->where('kind', 'chemical_store')->sole();
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($poison, 20, 120, \App\Modules\Finance\Accounting\Models\FinWarehouse::find($chem->warehouse_id),
            ['source_type' => 'test', 'source_id' => 1, 'contra' => config('finance.ap_control')]));
        $this->expectsRefusal(fn () => app(\App\Modules\Agri\Services\ChemicalControls::class)->assertStorable($oxidiser, $chem->warehouse_id), 'may not be stored with');

        // Only the named person may take it out of the locked store.
        $this->expectsRefusal(fn () => app(FarmStores::class)->issue($farm, $poison, 5, ['warehouse' => \App\Modules\Finance\Accounting\Models\FinWarehouse::find($chem->warehouse_id),
            'source_type' => 'test', 'source_id' => 2, 'actor' => $this->supervisor]), 'not authorised');
        $done = $this->asCompany($this->company, fn () => app(FarmStores::class)->issue($farm, $poison, 5, ['warehouse' => \App\Modules\Finance\Accounting\Models\FinWarehouse::find($chem->warehouse_id),
            'source_type' => 'test', 'source_id' => 3, 'actor' => $this->manager]));
        $this->assertEqualsWithDelta(600.0, $done['value'], 0.01);

        // An expired registration stops everyone.
        $this->asCompany($this->company, fn () => ChemicalProduct::withoutGlobalScopes()->where('stock_item_id', $poison->id)->update(['registration_expires_on' => today()->subDay()]));
        $this->expectsRefusal(fn () => app(FarmStores::class)->issue($farm, $poison, 1, ['warehouse' => \App\Modules\Finance\Accounting\Models\FinWarehouse::find($chem->warehouse_id),
            'source_type' => 'test', 'source_id' => 4, 'actor' => $this->manager]), 'registration');
    }

    public function test_a_transfer_between_farm_stores_is_dispatched_then_received_and_reorder_suggestions_come_from_use(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $diesel = $this->stockItem('DIESEL', 'l', 21);
        $this->stockOnFarm($farm, $diesel, 1000, 21);
        $this->actingAs($this->manager)->post(route('agri.stock.stores.store'), ['farm_id' => $farm->uuid, 'kind' => 'fuel_tank', 'name' => 'Bowser'])->assertSessionHasNoErrors();
        $main = FarmStore::withoutGlobalScopes()->where('kind', 'main')->sole();
        $tank = FarmStore::withoutGlobalScopes()->where('kind', 'fuel_tank')->sole();

        $this->actingAs($this->manager)->post(route('agri.stock.transfers.store'), ['from_store_id' => $main->uuid, 'to_store_id' => $tank->uuid,
            'lines' => [['item_id' => $diesel->id, 'qty' => 400]]])->assertSessionHasNoErrors();
        $order = \App\Modules\Operations\WarehouseManagement\Models\TransferOrder::withoutGlobalScopes()->sole();
        $ledger = app(StockLedger::class);
        $this->assertEqualsWithDelta(1000.0, $ledger->available($diesel->fresh(), $main->warehouse_id), 0.001, 'Nothing moves until it is dispatched.');
        $this->actingAs($this->manager)->post(route('agri.stock.transfers.step', $order->uuid), ['step' => 'dispatch'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(600.0, $ledger->available($diesel->fresh(), $main->warehouse_id), 0.001);
        $this->actingAs($this->manager)->post(route('agri.stock.transfers.step', $order->uuid), ['step' => 'receive'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(400.0, $ledger->available($diesel->fresh(), $tank->warehouse_id), 0.001);
        $this->assertSame('RECEIVED', $order->fresh()->status);

        // Heavy use over the last weeks: a suggestion appears, with its working.
        $wh = fn ($store) => \App\Modules\Finance\Accounting\Models\FinWarehouse::find($store->warehouse_id);
        $this->asCompany($this->company, fn () => app(FarmStores::class)->issue($farm, $diesel, 590, ['source_type' => 'test', 'source_id' => 9, 'date' => today()->subDays(10)->toDateString()]));
        $this->asCompany($this->company, fn () => app(FarmStores::class)->issue($farm, $diesel, 310, ['warehouse' => $wh($tank), 'source_type' => 'test', 'source_id' => 10, 'date' => today()->subDays(5)->toDateString()]));
        $s = collect($this->asCompany($this->company, fn () => app(FarmInventory::class)->reorderSuggestions($farm)))->firstWhere('item_id', $diesel->id);
        $this->assertNotNull($s, '100 l left at 10 l a day will not last the lead time.');
        $this->assertEquals(10.0, $s['per_day']);             // 900 l over 90 days
        $this->assertEquals(100, $s['available']);
        $this->assertEquals(340, $s['suggest']);              // 10 l × (14 days lead + 30) − 100 l
    }

    private function expectsRefusal(callable $fn, string $contains): void
    {
        try {
            $this->asCompany($this->company, $fn);
            $this->fail("Expected a refusal containing '{$contains}'.");
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString($contains, collect($e->errors())->flatten()->implode(' '));
        }
    }

    public function test_every_stock_control_tab_opens(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        foreach (\App\Modules\Agri\Http\Controllers\FarmInventoryController::TABS as $tab) {
            $this->actingAs($this->manager)->get(route('agri.stock.control', ['tab' => $tab, 'farm' => $farm->uuid]))->assertOk()
                ->assertInertia(fn ($p) => $p->component('Modules/Agri/Stock/Control')->where('tab', $tab));
        }
    }
}
