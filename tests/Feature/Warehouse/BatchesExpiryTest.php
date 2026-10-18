<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Allocation;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\Batches;
use App\Modules\Operations\WarehouseManagement\Services\BinTransfers;
use App\Modules\Operations\WarehouseManagement\Services\ExpiryWatch;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** WMS-090 batches, WMS-091 serial numbers, WMS-092 rotation, WMS-093 expiry. */
class BatchesExpiryTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $a;

    private FinStockBin $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('EXP', ['is_wms_managed' => true, 'manager_user_id' => $this->manager->id, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $loc = app(LocationService::class);
            $this->a = $loc->create($this->wh, ['code' => 'P-01', 'pick_sequence' => 1]);
            $this->b = $loc->create($this->wh, ['code' => 'P-02', 'pick_sequence' => 2]);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'YOGURT', 'name' => 'Yogurt 1l', 'uom' => 'ea', 'standard_cost' => 10]);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function lot(string $number, ?string $expires = null, string $kind = 'LOT'): FinStockLot
    {
        return $this->in(fn () => app(StockLedger::class)->lot($this->item->fresh(), $number, array_filter(['expires_on' => $expires, 'kind' => $kind])));
    }

    private function receive(FinStockBin $bin, float $qty, ?FinStockLot $lot = null): void
    {
        $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), $qty, 10, $this->wh, ['bin_id' => $bin->id, 'lot_id' => $lot?->id]));
    }

    private function profile(array $values): void
    {
        DB::table('wms_item_profiles')->updateOrInsert(['stock_item_id' => $this->item->id], ['company_id' => $this->company->id] + $values);
    }

    // ── WMS-091 serial numbers ───────────────────────────────────────────────────────────────

    public function test_a_serial_number_is_one_unit_wherever_it_is(): void
    {
        $sn = $this->lot('SN-7001', null, 'SERIAL');
        $this->receive($this->a, 1, $sn);

        try {
            $this->receive($this->b, 1, $sn);
            $this->fail('The same serial number was received twice.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('SN-7001', $e->getMessage());
        }
        // Moving it is fine: out of one bin, into the other.
        $this->in(fn () => app(BinTransfers::class)->move($this->wh, $this->item, $this->a, $this->b, 1, $sn->id, 'AVAILABLE', $this->manager));

        // And the database itself refuses a second unit at commit, whatever wrote it.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(function () use ($sn) {
            DB::table('fin_stock_positions')->where('lot_id', $sn->id)->update(['qty' => 2]);
            DB::statement('SET CONSTRAINTS wms_serial_single_unit IMMEDIATE');
        });
    }

    public function test_a_batch_shows_where_it_is_and_everything_that_happened_to_it(): void
    {
        $lot = $this->lot('B-2210', now()->addDays(40)->toDateString());
        $this->receive($this->a, 30, $lot);
        $this->in(fn () => app(BinTransfers::class)->move($this->wh, $this->item, $this->a, $this->b, 10, $lot->id, 'AVAILABLE', $this->manager));

        $detail = $this->in(fn () => app(Batches::class)->detail($lot->fresh()));
        $this->assertEquals(30, $detail['lot']['on_hand']);
        $this->assertCount(2, $detail['positions']);
        $this->assertSame(['receipt', 'transfer'], array_values(array_unique(array_column($detail['history'], 'type'))));

        $page = $this->in(fn () => app(Batches::class)->register(['expiring_within' => 60, 'in_stock' => true]));
        $this->assertSame('B-2210', $page->items()[0]['lot']);
        $this->assertSame(40, $page->items()[0]['days_left']);
    }

    // ── WMS-092 rotation ─────────────────────────────────────────────────────────────────────

    private function allocate(float $qty): \App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder
    {
        return $this->in(function () use ($qty) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'R-'.uniqid(), [['item_id' => $this->item->id, 'qty' => $qty]], [], $this->manager);
            app(FulfilmentOrders::class)->release($o, $this->manager);

            return app(Allocator::class)->allocate($o->fresh());
        });
    }

    public function test_a_product_can_rotate_by_receipt_and_keep_the_shelf_life_a_customer_is_owed(): void
    {
        $older = $this->lot('OLD', now()->addDays(60)->toDateString());
        $sooner = $this->lot('SOON', now()->addDays(5)->toDateString());
        $this->receive($this->a, 5, $older);
        $this->receive($this->b, 5, $sooner);

        // The warehouse rotates FEFO, so SOON would leave first; this product needs ten days of life left.
        $this->profile(['min_remaining_shelf_life_days' => 10]);
        $order = $this->allocate(3);
        $this->assertSame([$older->id], Allocation::query()->whereHas('line', fn ($q) => $q->where('order_id', $order->id))->pluck('lot_id')->unique()->values()->all(),
            'A lot with too little shelf life left was allocated.');

        // FIFO for this product: the earliest-received batch leaves first, whatever its date.
        $this->profile(['min_remaining_shelf_life_days' => 0, 'rotation' => 'FIFO']);
        $next = $this->allocate(1);
        $this->assertSame($older->id, Allocation::query()->whereHas('line', fn ($q) => $q->where('order_id', $next->id))->value('lot_id'));
    }

    public function test_an_override_breaks_rotation_with_a_reason_but_never_sends_expired_stock(): void
    {
        $fresh = $this->lot('NEW', now()->addDays(90)->toDateString());
        $soon = $this->lot('SOON', now()->addDays(20)->toDateString());
        $gone = $this->lot('GONE', now()->subDay()->toDateString());
        $this->receive($this->a, 5, $fresh);
        $this->receive($this->b, 5, $soon);
        $this->in(fn () => DB::table('fin_stock_lots')->where('id', $gone->id)->update(['expires_on' => now()->addDay()->toDateString()]));
        $this->receive($this->b, 2, $gone);
        $this->in(fn () => DB::table('fin_stock_lots')->where('id', $gone->id)->update(['expires_on' => now()->subDay()->toDateString()]));

        $order = $this->in(function () {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'OVR-1', [['item_id' => $this->item->id, 'qty' => 2]], [], $this->manager);

            return app(FulfilmentOrders::class)->release($o, $this->manager);
        });
        $line = $order->lines()->first();

        try {
            $this->in(fn () => app(Allocator::class)->override($line, $this->b->id, $gone->id, 2, 'Customer asked', $this->manager));
            $this->fail('An override sent expired stock.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
        $a = $this->in(fn () => app(Allocator::class)->override($line->fresh(), $this->a->id, $fresh->id, 2, 'Customer wants the long-dated batch', $this->manager));
        $this->assertSame('Customer wants the long-dated batch', $a->override_reason);
        $this->assertSame($this->manager->id, $a->override_by);
        $this->assertNotNull($a->reservation_id);
    }

    // ── WMS-093 expiry ───────────────────────────────────────────────────────────────────────

    public function test_expiry_risk_weighs_demand_before_the_date_and_warns_once_per_threshold(): void
    {
        $lot = $this->lot('E-1', now()->addDays(25)->toDateString());
        $this->receive($this->a, 100, $lot);
        // 90 issued over the last 90 days: one a day, so about 25 will be used before expiry.
        $this->in(fn () => app(StockLedger::class)->issueToProduction($this->item->fresh(), 90, $this->wh, ['bin_id' => $this->a->id, 'lot_id' => $lot->id, 'source_type' => 'TEST', 'source_id' => 1]));
        $this->receive($this->a, 90, $lot);
        $this->profile(['expiry_alert_days' => json_encode([60, 30, 14])]);

        $row = collect($this->in(fn () => app(ExpiryWatch::class)->atRisk($this->wh->id, 60)))->firstWhere('lot', 'E-1');
        $this->assertEquals(100, $row['qty']);
        $this->assertEquals(25, $row['demand_before_expiry']);
        $this->assertEquals(75, $row['at_risk_qty']);
        $this->assertEquals(750, $row['exposure']);
        $this->assertSame([60, 30, 14], $row['thresholds']);

        $this->assertSame(1, $this->in(fn () => app(ExpiryWatch::class)->alert()));
        $this->assertSame(0, $this->in(fn () => app(ExpiryWatch::class)->alert()), 'The same warning was given twice.');
        $this->assertSame(30, (int) DB::table('wms_expiry_alerts')->where('lot_id', $lot->id)->value('threshold_days'));
        $this->assertSame(1, $this->manager->notifications()->where('data', 'like', '%wms_expiry%')->count());
    }

    public function test_the_batch_serial_and_expiry_screens(): void
    {
        $sn = $this->lot('SN-9', null, 'SERIAL');
        $this->receive($this->a, 1, $sn);

        $this->actingAs($this->viewer)->get(route('warehouse.batches.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Batches/Index'));
        $this->actingAs($this->viewer)->get(route('warehouse.batches.serial', ['q' => 'SN-9']))->assertRedirect(route('warehouse.batches.show', $sn->uuid));
        $this->actingAs($this->viewer)->get(route('warehouse.batches.show', $sn->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->where('lot.serial_state', 'IN_STOCK')->where('positions.0.bin', 'P-01'));
        $this->actingAs($this->viewer)->get(route('warehouse.batches.serial', ['q' => 'NOPE']))->assertOk()->assertInertia(fn ($p) => $p->where('not_found', true));
        $this->actingAs($this->viewer)->get(route('warehouse.batches.expiry'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Batches/Expiry'));
    }
}
