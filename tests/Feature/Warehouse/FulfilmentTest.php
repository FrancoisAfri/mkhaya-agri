<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\SalesOrderService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Models\PickWave;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\BarcodeRegistry;
use App\Modules\Operations\WarehouseManagement\Services\Dispatching;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentSla;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\Packing;
use App\Modules\Operations\WarehouseManagement\Services\Picking;
use App\Modules\Operations\WarehouseManagement\Support\Gs1Parser;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WMS-060 to WMS-066: an order from intake to proof of delivery, through the ledger and back into
 * Finance's sales order.
 */
class FulfilmentTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $a1;

    private FinStockBin $a2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('DC', ['is_default' => true, 'is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $loc = app(LocationService::class);
            $zone = $loc->create($this->wh, ['code' => 'Z1', 'level' => 'ZONE']);
            $this->a1 = $loc->create($this->wh, ['code' => 'Z1-A1', 'parent_id' => $zone->id, 'pick_sequence' => 1]);
            $this->a2 = $loc->create($this->wh, ['code' => 'Z1-A2', 'parent_id' => $zone->id, 'pick_sequence' => 2]);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'JAM', 'name' => 'Apricot jam', 'uom' => 'ea', 'standard_cost' => 20]);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function stock(FinStockBin $bin, float $qty, ?string $lot = null, ?string $expires = null): void
    {
        $this->asCompany($this->company, function () use ($bin, $qty, $lot, $expires) {
            $lotId = $lot ? app(StockLedger::class)->lot($this->item->fresh(), $lot, array_filter(['expires_on' => $expires]))->id : null;
            app(StockLedger::class)->receive($this->item->fresh(), $qty, 20, $this->wh, ['bin_id' => $bin->id, 'lot_id' => $lotId]);
        });
    }

    private function order(float $qty, string $ref = 'WEB-1', array $attrs = []): FulfilmentOrder
    {
        return $this->asCompany($this->company, function () use ($qty, $ref, $attrs) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', $ref, [['item_id' => $this->item->id, 'qty' => $qty]], $attrs + ['customer_name' => 'Shop customer'], $this->manager);

            return app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o));
        });
    }

    private function pickAll(FulfilmentOrder $o, string $strategy = 'DISCRETE'): void
    {
        $this->asCompany($this->company, function () use ($o, $strategy) {
            app(Picking::class)->release($this->wh, collect([$o->fresh()]), $strategy, $this->manager);
            foreach (PickTask::withoutGlobalScopes()->where('order_id', $o->id)->where('status', 'OPEN')->get() as $t) {
                app(Picking::class)->confirm($t, (float) $t->qty, $this->manager);
            }
        });
    }

    private function packAll(FulfilmentOrder $o): Package
    {
        return $this->asCompany($this->company, function () use ($o) {
            $p = app(Packing::class)->open($o->fresh(), 'CARTON', $this->manager);
            foreach ($o->fresh()->lines as $l) {
                if ((float) $l->qty_picked - (float) $l->qty_packed > 0) {
                    app(Packing::class)->add($p, $l, (float) $l->qty_picked - (float) $l->qty_packed);
                }
            }

            return app(Packing::class)->close($p->fresh(), ['weight_kg' => 4.2]);
        });
    }

    // ── WMS-060 order sources ────────────────────────────────────────────────────────────────

    public function test_the_same_demand_is_accepted_once(): void
    {
        $a = $this->asCompany($this->company, fn () => app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'SHOPIFY-1001', [['item_id' => $this->item->id, 'qty' => 2]]));
        $b = $this->asCompany($this->company, fn () => app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'SHOPIFY-1001', [['item_id' => $this->item->id, 'qty' => 2]]));

        $this->assertTrue($a[1]);
        $this->assertFalse($b[1]);
        $this->assertSame($a[0]->id, $b[0]->id);
        $this->assertSame(1, FulfilmentOrder::withoutGlobalScopes()->where('external_ref', 'SHOPIFY-1001')->count());
    }

    public function test_the_api_takes_an_order_once_and_reports_what_can_be_promised(): void
    {
        $this->stock($this->a1, 10);
        \Laravel\Sanctum\Sanctum::actingAs($this->manager, ['wms:orders', 'wms:read']);
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->company->id);
        $payload = ['warehouse' => 'DC', 'source' => 'ECOMMERCE', 'external_ref' => 'WOO-77', 'lines' => [['sku' => 'JAM', 'qty' => 3]]];

        $this->postJson('/api/v1/warehouse/orders', $payload)->assertCreated()->assertJsonPath('status', 'ALLOCATED');
        $this->postJson('/api/v1/warehouse/orders', $payload)->assertOk()->assertJsonPath('external_ref', 'WOO-77');
        $this->getJson('/api/v1/warehouse/stock?sku=JAM')->assertOk()->assertJsonPath('0.available_to_promise', 7);
        $this->getJson('/api/v1/warehouse/orders/WOO-77')->assertOk()->assertJsonPath('lines.0.allocated', 3);
    }

    // ── WMS-061 allocation ───────────────────────────────────────────────────────────────────

    public function test_allocation_is_first_expiring_first_and_skips_expired_and_blocked_stock(): void
    {
        $this->stock($this->a1, 5, 'LATE', now()->addYear()->toDateString());
        $this->stock($this->a2, 5, 'SOON', now()->addMonth()->toDateString());
        $old = $this->asCompany($this->company, fn () => app(LocationService::class)->create($this->wh, ['code' => 'OLD']));
        $this->stock($old, 5, 'GONE', now()->subDay()->toDateString());
        $broken = $this->asCompany($this->company, fn () => app(LocationService::class)->create($this->wh, ['code' => 'BROKEN']));
        $this->stock($broken, 5);
        $this->asCompany($this->company, fn () => app(LocationService::class)->block($broken, 'Collapsed shelf'));

        $o = $this->order(7);

        $allocs = $o->lines()->first()->allocations()->orderBy('id')->get();
        $this->assertSame([$this->a2->id, $this->a1->id], $allocs->pluck('bin_id')->all(), 'Did not take the soon-expiring lot first.');
        $this->assertEquals([5, 2], $allocs->map(fn ($a) => (float) $a->qty)->all());
        $this->assertSame('ALLOCATED', $o->fresh()->status);
    }

    public function test_two_orders_cannot_both_have_the_last_units(): void
    {
        $this->stock($this->a1, 5);
        $first = $this->order(4, 'A');
        $second = $this->order(4, 'B');

        $this->assertSame('ALLOCATED', $first->fresh()->status);
        $this->assertSame('PARTIALLY_ALLOCATED', $second->fresh()->status);
        $this->assertEquals(1, (float) $second->lines()->first()->qty_allocated);
        $this->assertEquals(0, (float) app(StockLedger::class)->available($this->item->fresh(), $this->wh->id));
    }

    public function test_an_order_that_may_not_ship_partly_holds_nothing_until_it_can_hold_everything(): void
    {
        $this->stock($this->a1, 3);
        $o = $this->order(5, 'WHOLE', ['allow_partial' => false]);

        $this->assertSame('BACKORDERED', $o->fresh()->status);
        $this->assertEquals(0, (float) $o->lines()->first()->qty_allocated);
        $this->assertSame(0, FinStockReservation::withoutGlobalScopes()->where('status', 'OPEN')->where('item_id', $this->item->id)->count());
    }

    public function test_a_bin_blocked_after_allocation_is_reallocated_elsewhere(): void
    {
        $this->stock($this->a1, 5);
        $this->stock($this->a2, 5);
        $o = $this->order(4);
        $first = $o->lines()->first()->allocations()->first();
        $this->asCompany($this->company, fn () => app(LocationService::class)->block(FinStockBin::query()->find($first->bin_id), 'Spill'));

        $released = $this->asCompany($this->company, fn () => app(Allocator::class)->reallocate($o->fresh()));

        $this->assertSame(1, $released);
        $active = $o->lines()->first()->allocations()->where('status', 'ACTIVE')->get();
        $this->assertNotContains($first->bin_id, $active->pluck('bin_id')->all());
        $this->assertEquals(4, $active->sum('qty'));
    }

    public function test_an_approved_substitute_fills_a_shortage_on_its_own_line(): void
    {
        $other = $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'JAM-B', 'name' => 'Jam (other brand)', 'uom' => 'ea']));
        $this->asCompany($this->company, function () use ($other) {
            app(ItemCatalogue::class)->addSubstitute($this->item, $other, 1, false);
            app(StockLedger::class)->receive($other->fresh(), 10, 18, $this->wh, ['bin_id' => $this->a2->id]);
        });
        $this->stock($this->a1, 2);

        $o = $this->order(5, 'SUB', ['allow_substitution' => true]);

        $lines = $o->fresh()->lines;
        $this->assertCount(2, $lines);
        $this->assertEquals(3, (float) $lines[0]->qty_cancelled, 'The original line lost its history.');
        $this->assertSame($lines[0]->id, $lines[1]->substitute_of_line_id);
        $this->assertSame('ALLOCATED', $o->fresh()->status);
    }

    // ── WMS-062/063 picking ──────────────────────────────────────────────────────────────────

    public function test_strategies_group_and_order_the_walk(): void
    {
        $this->stock($this->a1, 20);
        $this->stock($this->a2, 20);
        $orders = collect([$this->order(25, 'C1'), $this->order(5, 'C2')]);

        $waves = $this->asCompany($this->company, fn () => app(Picking::class)->release($this->wh, $orders->map->fresh(), 'CLUSTER', $this->manager));

        $this->assertCount(1, $waves);
        $tasks = PickTask::withoutGlobalScopes()->where('wave_id', $waves[0]->id)->orderBy('sequence')->get();
        $this->assertSame([$this->a1->id], $tasks->pluck('bin_id')->unique()->take(1)->values()->all(), 'The walk does not start at the first bin.');
        $this->assertEqualsCanonicalizing([1, 2], $tasks->pluck('cluster_slot')->unique()->values()->all());
        $this->assertSame('Z1', $tasks->first()->zone);
    }

    public function test_a_pick_is_scan_checked_and_a_wrong_scan_is_counted(): void
    {
        $this->asCompany($this->company, fn () => app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory::class)->save(['policies' => ['scan_to_confirm' => true]], $this->wh));
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item, ['is_lot_tracked' => false]));
        $this->stock($this->a1, 5, 'L1');
        $o = $this->order(2);
        $this->asCompany($this->company, fn () => app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->manager));
        $task = PickTask::withoutGlobalScopes()->where('order_id', $o->id)->firstOrFail();

        foreach ([
            [['bin_scan' => 'DC.Z1-A2', 'item_scan' => 'JAM', 'lot_scan' => 'L1'], 'Wrong bin'],
            [['bin_scan' => 'DC.Z1-A1', 'item_scan' => 'JAM', 'lot_scan' => 'L9'], 'Wrong lot'],
        ] as [$scans, $msg]) {
            try {
                $this->asCompany($this->company, fn () => app(Picking::class)->confirm($task->fresh(), 2, $this->manager, $scans));
                $this->fail("Pick accepted: {$msg}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->assertSame(2, $task->fresh()->scan_errors);

        $this->asCompany($this->company, fn () => app(Picking::class)->confirm($task->fresh(), 2, $this->manager, ['bin_scan' => 'DC.Z1-A1', 'item_scan' => 'JAM', 'lot_scan' => 'L1']));
        $pack = app(FulfilmentOrders::class)->packingBin($this->wh);
        $this->assertEquals(2, (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $pack->id)->sum('qty'), 'A pick was ticked off without moving stock.');
    }

    public function test_a_short_pick_is_looked_for_elsewhere(): void
    {
        $this->stock($this->a1, 3);
        $this->stock($this->a2, 5);
        $o = $this->order(3);
        $this->asCompany($this->company, fn () => app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->manager));
        $task = PickTask::withoutGlobalScopes()->where('order_id', $o->id)->firstOrFail();

        $this->asCompany($this->company, fn () => app(Picking::class)->confirm($task, 1, $this->manager, [], true, 'Two crushed, not there'));

        $line = $o->lines()->first();
        $this->assertEquals(2, (float) $line->qty_short);
        $this->assertTrue($line->allocations()->where('status', 'ACTIVE')->where('bin_id', $this->a2->id)->exists(), 'The shortfall was not allocated from the other bin.');
        $this->assertEquals(2, (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $this->a1->id)->where('status', 'ON_HOLD')->sum('qty'),
            'The missing stock was left available to be promised again.');
    }

    // ── WMS-064/065 packing and dispatch ─────────────────────────────────────────────────────

    public function test_packing_checks_against_the_pick_and_gives_each_package_an_sscc(): void
    {
        $this->stock($this->a1, 5);
        $o = $this->order(3);
        $this->pickAll($o);
        $p = $this->asCompany($this->company, fn () => app(Packing::class)->open($o->fresh(), 'CARTON', $this->manager));

        $this->assertTrue(Gs1Parser::checkDigitValid($p->sscc));
        $this->assertSame(18, strlen($p->sscc));
        try {
            $this->asCompany($this->company, fn () => app(Packing::class)->add($p, $o->lines()->first(), 4));
            $this->fail('More was packed than picked.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only 3', $e->getMessage());
        }
        $this->expectExceptionMessage('empty package');
        $this->asCompany($this->company, fn () => app(Packing::class)->close($p));
    }

    public function test_dispatch_takes_stock_off_the_books_once_and_delivers_the_sales_order(): void
    {
        $party = $this->asCompany($this->company, fn () => FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'C'.Str::random(5), 'name' => 'Spar Rosebank']));
        DB::table('fin_items')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $this->wh->entity_id,
            'code' => 'JAM', 'name' => 'Apricot jam', 'unit' => 'ea', 'type' => 'inventory', 'sales_price' => 35, 'cost_price' => 20, 'tax_rate' => 15, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $finItem = DB::table('fin_items')->where('company_id', $this->company->id)->where('code', 'JAM')->value('id');
        $so = $this->asCompany($this->company, fn () => app(SalesOrderService::class)->save(['party_id' => $party->id, 'lines' => [[
            'account_id' => FinAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '4100')->value('id')
                ?? FinAccount::withoutGlobalScopes()->whereNull('company_id')->where('code', '4100')->value('id'),
            'item_id' => $finItem, 'description' => 'Jam', 'quantity' => 6, 'unit_price' => 35]]]));
        $this->stock($this->a1, 10);

        $o = $this->asCompany($this->company, fn () => app(FulfilmentOrders::class)->fromSalesOrder($so->fresh('lines'), $this->wh, $this->manager));
        $this->asCompany($this->company, fn () => app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o)));
        $this->pickAll($o);
        $pkg = $this->packAll($o);

        try {
            $this->asCompany($this->company, fn () => app(Dispatching::class)->dispatch($o->fresh(), collect([$pkg->fresh('contents')]), [], $this->manager));
            $this->fail('Dispatched without recording the handover.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        $shipment = $this->asCompany($this->company, fn () => app(Dispatching::class)->dispatch($o->fresh(), collect([$pkg->fresh('contents')]),
            ['carrier' => 'Courier Guy', 'tracking_reference' => 'CG-5', 'handover_name' => 'Driver Sipho'], $this->manager));

        $this->assertEquals(4, $this->item->fresh()->qty_on_hand);
        $m = FinStockMovement::withoutGlobalScopes()->where('type', 'dispatch')->firstOrFail();
        $this->assertNotNull($m->journal_id, 'The dispatch did not post cost of sales.');
        $this->assertSame(0, FinStockReservation::withoutGlobalScopes()->where('status', 'OPEN')->where('item_id', $this->item->id)->count(), 'The reservation was left open after dispatch.');
        $this->assertSame('DISPATCHED', $o->fresh()->status);
        $this->assertEquals(6, (float) $so->fresh('lines')->lines[0]->qty_delivered, 'Finance\'s sales order did not see the delivery.');
        $this->assertNotNull($shipment->delivery_note_id);

        // Invoicing the order must not relieve the stock a second time (WHMI decision 3).
        $this->asCompany($this->company, fn () => app(SalesOrderService::class)->invoice($so->fresh('lines')));
        $this->assertEquals(4, $this->item->fresh()->qty_on_hand, 'The invoice relieved stock the dispatch had already shipped.');

        $this->asCompany($this->company, fn () => app(Dispatching::class)->recordDelivery($shipment, 'M. Naidoo', null, null, 'Signed in store'));
        $this->assertSame('DELIVERED', $o->fresh()->status);
    }

    public function test_a_partial_dispatch_leaves_the_rest_waiting(): void
    {
        $this->stock($this->a1, 10);
        $o = $this->order(6);
        $this->pickAll($o);
        $line = $o->lines()->first();
        [$p1, $p2] = $this->asCompany($this->company, function () use ($o, $line) {
            $p1 = app(Packing::class)->open($o->fresh(), 'CARTON', $this->manager);
            app(Packing::class)->add($p1, $line->fresh(), 4);
            app(Packing::class)->close($p1->fresh());
            $p2 = app(Packing::class)->open($o->fresh(), 'CARTON', $this->manager);
            app(Packing::class)->add($p2, $line->fresh(), 2);
            app(Packing::class)->close($p2->fresh());

            return [$p1, $p2];
        });

        $this->asCompany($this->company, fn () => app(Dispatching::class)->dispatch($o->fresh(), collect([$p1->fresh('contents')]), ['handover_name' => 'Driver'], $this->manager));
        $this->assertSame('PARTIALLY_DISPATCHED', $o->fresh()->status);
        $this->assertEquals(4, (float) $line->fresh()->qty_dispatched);
        $this->asCompany($this->company, fn () => app(Dispatching::class)->dispatch($o->fresh(), collect([$p2->fresh('contents')]), ['handover_name' => 'Driver'], $this->manager));
        $this->assertSame('DISPATCHED', $o->fresh()->status);
    }

    public function test_cancelling_lets_reservations_go_and_puts_picked_stock_back(): void
    {
        $this->stock($this->a1, 5);
        $o = $this->order(3);
        $this->pickAll($o);
        $this->asCompany($this->company, fn () => app(FulfilmentOrders::class)->cancel($o->fresh(), 'Customer changed their mind'));

        $this->assertSame('CANCELLED', $o->fresh()->status);
        $this->assertSame(0, FinStockReservation::withoutGlobalScopes()->where('status', 'OPEN')->where('item_id', $this->item->id)->count());
        $this->assertTrue(\App\Modules\Operations\WarehouseManagement\Models\PutawayTask::withoutGlobalScopes()->where('source_type', 'WMS_ORDER_CANCELLED')->where('qty', 3)->exists());
    }

    // ── WMS-066 service levels ───────────────────────────────────────────────────────────────

    public function test_an_order_near_its_deadline_is_flagged_once_and_the_people_on_shift_are_told(): void
    {
        $this->asCompany($this->company, function () {
            app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory::class)->assignStaff($this->wh, $this->viewer->id, ['PICK']);
            app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory::class)->assignStaff($this->wh, $this->manager->id, ['SUPERVISE']);
        });
        $this->stock($this->a1, 5);
        $o = $this->order(2, 'LATE', ['required_by' => now()->addMinutes(30)]);

        $this->assertSame(1, $this->asCompany($this->company, fn () => app(FulfilmentSla::class)->checkAtRisk()));
        $this->assertSame(0, $this->asCompany($this->company, fn () => app(FulfilmentSla::class)->checkAtRisk()), 'The same order was flagged twice.');
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $this->viewer->id)->where('data', 'like', '%wms_order_at_risk%')->exists());

        $this->pickAll($o);
        $m = $this->asCompany($this->company, fn () => app(FulfilmentSla::class)->metrics(now()->subDay(), now()->addDay(), $this->wh->id));
        $this->assertSame(1, $m['orders']);
        $this->assertEquals(1, $m['picking_accuracy']);
        $this->artisan('wms:fulfilment', ['--company' => $this->company->id])->assertSuccessful();
    }

    // ── Screens ──────────────────────────────────────────────────────────────────────────────

    public function test_every_outbound_screen_renders_and_the_whole_flow_runs_through_the_routes(): void
    {
        $this->stock($this->a1, 10);
        $this->post(route('warehouse.outbound.orders.store'), ['warehouse' => $this->wh->uuid, 'kind' => 'INTERNAL', 'reference' => 'MAINT-9',
            'customer_name' => 'Maintenance', 'lines' => [['item' => $this->item->uuid, 'qty' => 2]]])->assertSessionHasNoErrors()->assertRedirect();
        $o = FulfilmentOrder::withoutGlobalScopes()->where('external_ref', 'MAINT-9')->firstOrFail();

        $this->post(route('warehouse.outbound.orders.act', $o->uuid), ['action' => 'release'])->assertSessionHasNoErrors();
        $this->post(route('warehouse.outbound.waves.store'), ['orders' => [$o->uuid], 'strategy' => 'BATCH'])->assertSessionHasNoErrors();
        $wave = PickWave::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->get(route('warehouse.outbound.waves.show', $wave->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Outbound/Wave')->has('tasks', 1));
        $task = PickTask::withoutGlobalScopes()->where('wave_id', $wave->id)->firstOrFail();
        $this->post(route('warehouse.outbound.picks.confirm', $task->uuid), ['qty' => 2])->assertSessionHasNoErrors();

        $this->post(route('warehouse.outbound.orders.pack', $o->uuid), ['action' => 'open', 'type' => 'BAG'])->assertSessionHasNoErrors();
        $pkg = Package::withoutGlobalScopes()->where('order_id', $o->id)->firstOrFail();
        $this->post(route('warehouse.outbound.orders.pack', $o->uuid), ['action' => 'add', 'package' => $pkg->uuid, 'line_id' => $o->lines()->first()->id, 'qty' => 2])->assertSessionHasNoErrors();
        $this->post(route('warehouse.outbound.orders.pack', $o->uuid), ['action' => 'close', 'package' => $pkg->uuid, 'weight_kg' => 1])->assertSessionHasNoErrors();
        $this->post(route('warehouse.outbound.orders.dispatch', $o->uuid), ['packages' => [$pkg->uuid], 'handover_name' => 'Workshop foreman'])->assertSessionHasNoErrors();
        $this->assertSame('DISPATCHED', $o->fresh()->status);

        $this->get(route('warehouse.outbound.orders.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Outbound/Orders'));
        $this->get(route('warehouse.outbound.orders.show', $o->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Outbound/OrderShow')->has('shipments', 1));
        $this->get(route('warehouse.outbound.orders.documents', $o->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Outbound/Documents')->has('packages', 1));
        $this->get(route('warehouse.outbound.waves.index'))->assertOk();
        $this->get(route('warehouse.outbound.sla'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Outbound/Sla'));
        $this->get(route('warehouse.hub.outbound'))->assertOk();
        foreach (['Orders', 'OrderShow', 'Waves', 'Wave', 'Documents', 'Sla'] as $page) {
            $this->assertFileExists(resource_path("js/Modules/Warehouse/Outbound/{$page}.vue"));
        }
    }
}
