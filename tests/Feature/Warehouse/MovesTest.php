<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\ReplenishmentTask;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Models\TransferOrder;
use App\Modules\Operations\WarehouseManagement\Services\BinTransfers;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\Replenishment;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\StockAvailability;
use App\Modules\Operations\WarehouseManagement\Services\TransferOrders;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** WMS-050 to WMS-053: bin moves, warehouse transfers, replenishment and its priorities. */
class MovesTest extends WarehouseTestCase
{
    private FinWarehouse $a;

    private FinWarehouse $b;

    private FinStockItem $item;

    private FinStockBin $bulk;

    private FinStockBin $face;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->a = $this->warehouse('JHB', ['policies' => ['scan_to_confirm' => false]]);
        $this->b = $this->warehouse('CPT', ['policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            $loc = app(LocationService::class);
            $this->bulk = $loc->create($this->a, ['code' => 'BULK-1']);
            $this->face = $loc->create($this->a, ['code' => 'PICK-1', 'max_units' => 50, 'pick_sequence' => 1]);
            $loc->create($this->b, ['code' => 'IN', 'kind' => 'RECEIVING']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SUGAR', 'name' => 'Sugar 1kg', 'uom' => 'ea', 'standard_cost' => 10]);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function stock(FinStockBin $bin, float $qty, ?int $lotId = null): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->item->fresh(), $qty, 10, FinWarehouse::query()->find($bin->warehouse_id), ['bin_id' => $bin->id, 'lot_id' => $lotId]));
    }

    private function at(FinStockBin $bin, string $status = 'AVAILABLE'): float
    {
        return (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $bin->id)->where('status', $status)->sum('qty');
    }

    // ── WMS-050 bin moves ────────────────────────────────────────────────────────────────────

    public function test_a_bin_move_keeps_the_lot_and_refuses_a_prohibited_bin(): void
    {
        $lot = $this->asCompany($this->company, fn () => app(StockLedger::class)->lot($this->item->fresh(), 'SN-0001', ['kind' => 'SERIAL']));
        $this->stock($this->bulk, 1, $lot->id);
        $blocked = $this->asCompany($this->company, fn () => tap(app(LocationService::class)->create($this->a, ['code' => 'BAD']), fn ($x) => app(LocationService::class)->block($x, 'Flooded')));

        try {
            $this->asCompany($this->company, fn () => app(BinTransfers::class)->move($this->a, $this->item, $this->bulk, $blocked->fresh(), 1, $lot->id, 'AVAILABLE', $this->manager));
            $this->fail('Moved into a blocked bin.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => app(BinTransfers::class)->move($this->a, $this->item, $this->bulk, $this->face, 1, $lot->id, 'AVAILABLE', $this->manager));
        $this->assertSame($lot->id, FinStockPosition::withoutGlobalScopes()->where('bin_id', $this->face->id)->value('lot_id'), 'The serial number did not travel with the unit.');
    }

    public function test_more_than_the_bin_holds_cannot_be_moved(): void
    {
        $this->stock($this->bulk, 5);

        $this->expectExceptionMessage('Only 5');

        $this->asCompany($this->company, fn () => app(BinTransfers::class)->move($this->a, $this->item, $this->bulk, $this->face, 6, null, 'AVAILABLE', $this->manager));
    }

    // ── WMS-051 transfers ────────────────────────────────────────────────────────────────────

    public function test_a_transfer_runs_from_draft_to_reconciled_with_stock_in_transit_in_between(): void
    {
        $this->stock($this->bulk, 100);
        $svc = app(TransferOrders::class);
        $o = $this->asCompany($this->company, fn () => $svc->draft($this->a, $this->b, [['item_id' => $this->item->id, 'qty' => 40, 'from_bin_id' => $this->bulk->id]], $this->manager));

        $clerk = $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::MOVE_STOCK]);
        try {
            $svc->approve($o, $clerk);
            $this->fail('A transfer was approved without the permission.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->asCompany($this->company, function () use ($svc, $o) {
            $svc->approve($o, $this->manager);
            $svc->pick($o->fresh('lines'), [], $this->manager);
            $svc->dispatch($o->fresh('lines'), $this->manager, ['carrier' => 'Own fleet', 'tracking_reference' => 'TRK-1']);
        });

        $transit = $svc->transitBin($this->b);
        $this->assertEquals(60, $this->at($this->bulk));
        $this->assertEquals(40, $this->at($transit));
        $atB = collect($this->asCompany($this->company, fn () => app(StockAvailability::class)->rows([$this->item->id], $this->b->id)))->first();
        $this->assertEquals(40, $atB['in_transit']);
        $this->assertEquals(0, $atB['available_to_promise'], 'Stock on the truck could be promised at the destination.');

        $line = $o->fresh('lines')->lines[0];
        $this->asCompany($this->company, fn () => $svc->receive($o->fresh(), [$line->id => ['qty' => 35]], $this->manager));
        $this->assertSame('PARTIALLY_RECEIVED', $o->fresh()->status);

        try {
            $this->asCompany($this->company, fn () => $svc->receive($o->fresh(), [$line->id => ['qty' => 10]], $this->manager));
            $this->fail('More was received than was dispatched.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be received twice', $e->getMessage());
        }

        try {
            $this->asCompany($this->company, fn () => $svc->reconcile($o->fresh('lines'), $this->manager));
            $this->fail('A short transfer reconciled with no explanation.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        $this->asCompany($this->company, fn () => $svc->reconcile($o->fresh('lines'), $this->manager, 'Carton crushed on the N1'));

        $this->assertSame('RECONCILED', $o->fresh()->status);
        $this->assertEquals(5, (float) $line->fresh()->variance_qty);
        $adj = StockAdjustment::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('TRANSIT_LOSS', DB::table('wms_reason_codes')->where('id', $adj->reason_code_id)->value('code'));
        $this->assertSame(StockAdjustment::POSTED, $adj->status);
        $this->assertEquals(0, $this->at($transit), 'Lost stock was left sitting in transit.');
    }

    public function test_a_dispatched_transfer_can_be_recalled_and_an_undispatched_one_simply_cancelled(): void
    {
        $this->stock($this->bulk, 20);
        $svc = app(TransferOrders::class);
        $o = $this->asCompany($this->company, function () use ($svc) {
            $o = $svc->draft($this->a, $this->b, [['item_id' => $this->item->id, 'qty' => 8, 'from_bin_id' => $this->bulk->id]], $this->manager);
            $svc->approve($o, $this->manager);
            $svc->pick($o->fresh('lines'), [], $this->manager);

            return $svc->dispatch($o->fresh('lines'), $this->manager);
        });
        $this->assertEquals(12, $this->at($this->bulk));

        $this->asCompany($this->company, fn () => $svc->cancel($o->fresh('lines'), $this->manager, 'Wrong destination'));
        $this->assertEquals(20, $this->at($this->bulk), 'A recalled transfer did not bring the stock back.');
        $this->assertSame('CANCELLED', $o->fresh()->status);
    }

    // ── WMS-052 / WMS-053 replenishment ──────────────────────────────────────────────────────

    public function test_a_pick_face_below_minimum_is_topped_up_from_bulk_first_expiring_first(): void
    {
        $soon = $this->asCompany($this->company, fn () => app(StockLedger::class)->lot($this->item->fresh(), 'L-SOON', ['expires_on' => now()->addMonth()->toDateString()]));
        $late = $this->asCompany($this->company, fn () => app(StockLedger::class)->lot($this->item->fresh(), 'L-LATE', ['expires_on' => now()->addYear()->toDateString()]));
        $bulk2 = $this->asCompany($this->company, fn () => app(LocationService::class)->create($this->a, ['code' => 'BULK-2']));
        $this->stock($this->bulk, 30, $late->id);
        $this->stock($bulk2, 30, $soon->id);
        $this->stock($this->face, 4);
        $svc = app(Replenishment::class);
        $this->asCompany($this->company, fn () => $svc->saveRule($this->a, $this->item, ['source' => 'BULK', 'pick_bin_id' => $this->face->id, 'min_qty' => 5, 'max_qty' => 40, 'multiple' => 12]));

        // 40 - 4 = 36, already a multiple of 12: all 30 of the soon-expiring lot, then 6 of the later one.
        $this->assertSame(2, $this->asCompany($this->company, fn () => $svc->generate()));
        $this->assertSame(0, $this->asCompany($this->company, fn () => $svc->generate()), 'Running replenishment twice doubled the work.');

        $tasks = ReplenishmentTask::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame([$soon->id, $late->id], $tasks->pluck('lot_id')->all(), 'Replenishment did not take the first-expiring lot first.');
        $this->assertEquals([30, 6], $tasks->map(fn ($t) => (float) $t->qty)->all());

        $this->asCompany($this->company, fn () => $tasks->each(fn ($t) => $svc->complete($t, (float) $t->qty, $this->manager)));
        $this->assertEquals(40, $this->at($this->face));
    }

    public function test_priority_rises_with_short_orders_and_waiting_production(): void
    {
        $this->stock($this->bulk, 100);
        $other = $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SALT', 'name' => 'Salt', 'uom' => 'ea']));
        $face2 = $this->asCompany($this->company, fn () => app(LocationService::class)->create($this->a, ['code' => 'PICK-2']));
        $this->asCompany($this->company, function () use ($other, $face2) {
            app(StockLedger::class)->receive($other->fresh(), 100, 5, $this->a, ['bin_id' => $this->bulk->id]);
            app(StockLedger::class)->reserve($this->item->fresh(), $this->a, 20, 'SALES_ORDER', 9);
            app(StockLedger::class)->reserve($this->item->fresh(), $this->a, 5, 'PRODUCTION_ORDER', 3);
            app(Replenishment::class)->saveRule($this->a, $this->item, ['source' => 'BULK', 'pick_bin_id' => $this->face->id, 'min_qty' => 5, 'max_qty' => 30]);
            app(Replenishment::class)->saveRule($this->a, $other, ['source' => 'BULK', 'pick_bin_id' => $face2->id, 'min_qty' => 5, 'max_qty' => 30]);
            app(Replenishment::class)->generate();
        });

        $urgent = ReplenishmentTask::withoutGlobalScopes()->where('item_id', $this->item->id)->firstOrFail();
        $routine = ReplenishmentTask::withoutGlobalScopes()->where('item_id', $other->id)->firstOrFail();
        $this->assertGreaterThan($routine->priority, $urgent->priority);
        $this->assertContains('orders are already short of this item', $urgent->priority_reasons);
        $this->assertContains('a production order is waiting for it', $urgent->priority_reasons);
    }

    public function test_nothing_in_bulk_becomes_an_exception_and_a_warehouse_rule_drafts_a_transfer(): void
    {
        $svc = app(Replenishment::class);
        $this->asCompany($this->company, function () use ($svc) {
            $svc->saveRule($this->a, $this->item, ['source' => 'BULK', 'pick_bin_id' => $this->face->id, 'min_qty' => 5, 'max_qty' => 30]);
            $svc->saveRule($this->b, $this->item, ['source' => 'WAREHOUSE', 'source_warehouse_id' => $this->a->id, 'min_qty' => 10, 'max_qty' => 50]);
            $svc->generate();
        });

        $this->assertSame('FAILED', ReplenishmentTask::withoutGlobalScopes()->where('warehouse_id', $this->a->id)->value('status'));
        $t = TransferOrder::withoutGlobalScopes()->where('to_warehouse_id', $this->b->id)->firstOrFail();
        $this->assertSame('REPLENISHMENT', $t->source);
        $this->assertSame('DRAFT', $t->status);
    }

    public function test_items_at_their_reorder_point_become_a_procurement_purchase_request(): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item, ['reorder_level' => 50, 'safety_stock' => 20, 'lead_time_days' => 10, 'reorder_qty' => 100]));
        $this->stock($this->bulk, 30);

        $s = $this->asCompany($this->company, fn () => app(Replenishment::class)->reorderSuggestions());
        $this->assertSame('SUGAR', $s[0]['code']);
        $this->assertEquals(50, $s[0]['reorder_point']);

        $this->post(route('warehouse.floor.replenishment.request'), ['lines' => [$this->item->uuid => 100]])->assertSessionHasNoErrors();
        $line = DB::table('purchase_request_items')->where('stock_item_id', $this->item->id)->first();
        $this->assertNotNull($line, 'No purchase request line was raised for the stock item.');
        $this->assertEquals(100, (float) $line->quantity);
        $this->assertSame('draft', DB::table('purchase_requests')->where('id', $line->purchase_request_id)->value('status'));
    }

    public function test_the_scheduled_command_runs_per_company(): void
    {
        $this->stock($this->bulk, 50);
        $this->asCompany($this->company, fn () => app(Replenishment::class)->saveRule($this->a, $this->item, ['source' => 'BULK', 'pick_bin_id' => $this->face->id, 'min_qty' => 5, 'max_qty' => 20]));

        $this->artisan('wms:replenish', ['--company' => $this->company->id])->assertSuccessful();

        $this->assertSame(1, ReplenishmentTask::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_every_moves_screen_renders(): void
    {
        $this->stock($this->bulk, 10);
        $this->get(route('warehouse.floor.move', ['warehouse' => $this->a->uuid, 'bin' => 'BULK-1']))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Moves/BinMove')->has('contents', 1));
        $this->post(route('warehouse.floor.move.store'), ['warehouse' => $this->a->uuid, 'from' => $this->bulk->uuid, 'to' => $this->face->uuid,
            'item' => $this->item->uuid, 'status' => 'AVAILABLE', 'qty' => 4])->assertSessionHasNoErrors();
        $this->assertEquals(4, $this->at($this->face));
        $this->get(route('warehouse.floor.transfers.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Moves/Transfers'));
        $this->get(route('warehouse.floor.replenishment.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Moves/Replenishment'));
        foreach (['BinMove', 'Transfers', 'Replenishment'] as $page) {
            $this->assertFileExists(resource_path("js/Modules/Warehouse/Moves/{$page}.vue"));
        }
    }
}
