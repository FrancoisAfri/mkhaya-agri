<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LedgerVerifier;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\TransferOrders;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/** WMS-210..213, §25.4: the ledger is the truth and the projections are checked against it; work is atomic; health is visible; a restore is verified. */
class ReliabilityTest extends WarehouseTestCase
{
    private FinWarehouse $a;

    private FinWarehouse $b;

    private FinStockItem $sugar;

    private FinStockItem $salt;

    private FinStockBin $bulk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->a = $this->warehouse('RA', ['is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->b = $this->warehouse('RB', ['is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            $this->bulk = app(LocationService::class)->create($this->a, ['code' => 'RA-1']);
            app(LocationService::class)->create($this->b, ['code' => 'RB-IN', 'kind' => 'RECEIVING']);
            $this->sugar = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SUGAR', 'name' => 'Sugar', 'uom' => 'ea', 'standard_cost' => 10]);
            $this->salt = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SALT', 'name' => 'Salt', 'uom' => 'ea', 'standard_cost' => 5]);
            app(StockLedger::class)->receive($this->sugar->fresh(), 100, 10, $this->a, ['bin_id' => $this->bulk->id]);
            app(StockLedger::class)->receive($this->salt->fresh(), 20, 5, $this->a, ['bin_id' => $this->bulk->id]);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    public function test_the_ledger_check_finds_a_corrupted_projection_and_status_changes_are_not_false_alarms(): void
    {
        // Stock changes status inside the warehouse: positions move, the balance does not.
        $this->in(fn () => app(StockLedger::class)->changeStatus($this->sugar->fresh(), $this->a, 10, 'AVAILABLE', 'QUARANTINE', ['bin_id' => $this->bulk->id]));
        $this->assertTrue(app(LedgerVerifier::class)->verify($this->company->id)['ok']);
        $this->assertSame(0.0, collect(app(\App\Modules\Operations\WarehouseManagement\Services\StockHistory::class)->reconcile($this->sugar->id))->sum('difference'));

        // QA-027: somebody edits a projection directly.
        DB::table('fin_stock_balances')->where('item_id', $this->sugar->id)->where('warehouse_id', $this->a->id)->update(['qty' => 95]);
        $r = app(LedgerVerifier::class)->verify($this->company->id);
        $this->assertFalse($r['ok']);
        $kinds = collect($r['problems'])->where('item', 'SUGAR')->pluck('kind')->all();
        $this->assertContains('BALANCE_VS_LEDGER', $kinds);
        $this->assertContains('POSITIONS_VS_BALANCE', $kinds);
        $this->assertContains('ON_HAND_VS_BALANCES', $kinds);

        $this->artisan('wms:verify-ledger', ['--company' => $this->company->id])->assertExitCode(1);
        $this->assertTrue(DB::table('wms_audit_events')->where('company_id', $this->company->id)->where('action', 'ledger.mismatch')->exists());
        $this->get(route('warehouse.health', ['verify' => 1]))->assertOk()->assertInertia(fn ($p) => $p->where('ledger.ok', false));
    }

    public function test_a_multi_line_transfer_posts_whole_or_not_at_all(): void
    {
        $svc = app(TransferOrders::class);
        $o = $this->in(fn () => $svc->draft($this->a, $this->b, [['item_id' => $this->sugar->id, 'qty' => 30, 'from_bin_id' => $this->bulk->id],
            ['item_id' => $this->salt->id, 'qty' => 15, 'from_bin_id' => $this->bulk->id]], $this->manager));
        $this->in(function () use ($svc, $o) {
            $svc->approve($o, $this->manager);
            $svc->pick($o->fresh('lines'), [], $this->manager);
        });
        // Between pick and dispatch the salt goes elsewhere: the second leg of the dispatch fails.
        $this->in(fn () => app(StockLedger::class)->adjust($this->salt->fresh(), $this->a, -18, 5, ['bin_id' => $this->bulk->id, 'reason' => 'Sent elsewhere']));
        $before = DB::table('fin_stock_movements')->where('company_id', $this->company->id)->count();

        try {
            $this->in(fn () => $svc->dispatch($o->fresh('lines'), $this->manager));
            $this->fail('A transfer dispatched with a line that had no stock.');
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, DB::table('fin_stock_movements')->where('company_id', $this->company->id)->count(), 'the sugar leg posted although the salt leg failed');
        $this->assertSame(100.0, (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $this->sugar->id)->where('warehouse_id', $this->a->id)->sum('qty'));
        $this->assertSame('PICKED', $o->fresh()->status);
        $this->assertTrue(app(LedgerVerifier::class)->verify($this->company->id)['ok']);
    }

    public function test_stock_cannot_be_booked_to_a_bin_of_another_warehouse(): void
    {
        $other = $this->in(fn () => app(LocationService::class)->create($this->b, ['code' => 'RB-2']));
        $this->expectExceptionMessage('belongs to another warehouse');
        $this->in(fn () => app(StockLedger::class)->receive($this->sugar->fresh(), 1, 10, $this->a, ['bin_id' => $other->id]));
    }

    public function test_health_shows_stalled_scheduled_work_and_a_restore_check_finds_missing_documents(): void
    {
        WarehouseHealth::finished('wms:outbox', true);
        $state = fn () => collect(app(WarehouseHealth::class)->checks($this->company->id))->firstWhere('check', 'wms:outbox')['state'];
        $this->assertSame('green', $state());
        $this->travel(20)->minutes();
        $this->assertSame('red', $state(), 'a minutely job silent for 20 minutes is a stopped scheduler');
        $this->travelBack();
        WarehouseHealth::finished('wms:counts', false);
        $this->assertSame('red', collect(app(WarehouseHealth::class)->checks())->firstWhere('check', 'wms:counts')['state']);
        $this->artisan('wms:health')->assertExitCode(1);

        $this->artisan('wms:verify-restore', ['--company' => $this->company->id])->assertExitCode(0);
        DB::table('wms_evidence')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id, 'subject_type' => 'ADJUSTMENT', 'subject_id' => 1,
            'kind' => 'PHOTO', 'path' => 'wms/evidence/missing.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1,
            'uploaded_by' => $this->manager->id, 'created_at' => now()]);
        $this->artisan('wms:verify-restore', ['--company' => $this->company->id])->assertExitCode(1);
    }
}
