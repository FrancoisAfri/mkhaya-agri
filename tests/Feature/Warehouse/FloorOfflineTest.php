<?php

namespace Tests\Feature\Warehouse;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\Counting;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LabelPrinting;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\OfflineSync;
use App\Modules\Operations\WarehouseManagement\Services\Picking;
use App\Modules\Operations\WarehouseManagement\Services\PutawayTasks;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** WMS-150..153: the floor bundle, idempotent offline sync, conflicts for review (QA-017), labels and printers. */
class FloorOfflineTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $a;

    private FinStockBin $b;

    private User $picker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('FLR', ['is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $loc = app(LocationService::class);
            $this->a = $loc->create($this->wh, ['code' => 'F-01', 'pick_sequence' => 1]);
            $this->b = $loc->create($this->wh, ['code' => 'F-02', 'pick_sequence' => 2]);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'CUP', 'name' => 'Cup', 'uom' => 'ea', 'standard_cost' => 4]);
            app(StockLedger::class)->receive($this->item->fresh(), 10, 4, $this->wh, ['bin_id' => $this->a->id]);
        });
        $this->picker = $this->person('picker', [RoleCatalog::VIEW, RoleCatalog::PICK, RoleCatalog::COUNT, RoleCatalog::MOVE_STOCK]);
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function wave(float $qty = 2): PickTask
    {
        return $this->in(function () use ($qty) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'OFF-'.Str::random(4), [['item_id' => $this->item->id, 'qty' => $qty]], [], $this->manager);
            $o = app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o));
            app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->picker);

            return PickTask::query()->where('order_id', $o->id)->firstOrFail();
        });
    }

    private function action(string $kind, array $payload, ?string $ref = null): array
    {
        return ['client_ref' => $ref ?? (string) Str::uuid(), 'kind' => $kind, 'recorded_at' => now()->subMinutes(20)->toIso8601String(), 'payload' => $payload];
    }

    public function test_the_device_keeps_only_its_own_tasks_and_never_the_expected_quantity_or_a_balance(): void
    {
        $task = $this->wave();
        $this->in(fn () => app(Counting::class)->open(['warehouse_id' => $this->wh->id, 'scope' => 'BINS', 'scope_ids' => [$this->a->id]], $this->manager));

        $bundle = $this->in(fn () => app(OfflineSync::class)->bundle($this->picker));
        $this->assertSame($task->uuid, $bundle['picks'][0]['id']);
        $line = $bundle['counts'][0]['lines'][0];
        $this->assertArrayNotHasKey('expected', $line);
        $this->assertArrayNotHasKey('snapshot_qty', $line);
        $this->assertStringNotContainsString('available', json_encode($bundle), 'A stock balance was sent to the device.');
        $this->assertCount(0, $this->in(fn () => app(OfflineSync::class)->bundle($this->manager))['picks'], 'Someone else\'s picks were cached.');
    }

    public function test_an_offline_action_is_applied_once_however_often_it_arrives(): void
    {
        $count = $this->in(fn () => app(Counting::class)->open(['warehouse_id' => $this->wh->id, 'scope' => 'BINS', 'scope_ids' => [$this->a->id], 'recount_qty' => 100], $this->manager));
        $act = $this->action('COUNT_ENTRY', ['line_id' => $count->lines()->first()->id, 'qty' => 9]);

        $first = $this->in(fn () => app(OfflineSync::class)->apply($act, $this->picker));
        $again = $this->in(fn () => app(OfflineSync::class)->apply($act, $this->picker));
        $this->assertSame('APPLIED', $first['status']);
        $this->assertSame('DUPLICATE', $again['status']);
        $this->assertSame(1, DB::table('wms_count_entries')->where('count_line_id', $count->lines()->first()->id)->count());
        $this->assertNotNull(DB::table('wms_offline_actions')->where('client_ref', $act['client_ref'])->value('recorded_at'));
    }

    public function test_an_offline_pick_that_no_longer_fits_is_held_for_review_not_forced_through(): void
    {
        $task = $this->wave(2);
        // While the picker was offline, the stock left the bin.
        $this->in(fn () => app(StockLedger::class)->transfer($this->item->fresh(), $this->wh, $this->wh, 10, ['bin_id' => $this->a->id, 'to_bin_id' => $this->b->id]));

        $result = $this->in(fn () => app(OfflineSync::class)->apply($this->action('PICK_CONFIRM', ['task' => $task->uuid, 'qty' => 2, 'bin_scan' => 'F-01', 'item_scan' => 'CUP']), $this->picker));
        $this->assertSame('CONFLICT', $result['status'], 'An offline pick of stock that is no longer there was posted (QA-017).');
        $this->assertSame('OPEN', $task->fresh()->status);
        $this->assertEquals(10, (float) DB::table('fin_stock_positions')->where('bin_id', $this->b->id)->sum('qty'), 'The ledger changed despite the conflict.');

        $id = DB::table('wms_offline_actions')->where('status', 'CONFLICT')->value('id');
        try {
            $this->in(fn () => app(OfflineSync::class)->resolve($id, 'apply', $this->manager));
            $this->fail('A conflicting action was applied although it still does not fit.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still does not fit', $e->getMessage());
        }
        $this->in(fn () => app(OfflineSync::class)->resolve($id, 'discard', $this->manager));
        $this->assertSame('DISCARDED', DB::table('wms_offline_actions')->where('id', $id)->value('status'));
    }

    public function test_offline_put_away_lands_where_it_was_scanned(): void
    {
        $task = $this->in(fn () => app(PutawayTasks::class)->create($this->wh, $this->item, 4, ['from_bin_id' => $this->a->id]));
        $task->update(['assigned_to' => $this->picker->id]);
        $this->assertCount(1, $this->in(fn () => app(OfflineSync::class)->bundle($this->picker))['putaways']);

        $r = $this->in(fn () => app(OfflineSync::class)->apply($this->action('PUTAWAY_COMPLETE', ['task' => $task->uuid, 'to_bin' => 'F-02', 'qty' => 4]), $this->picker));
        $this->assertSame('APPLIED', $r['status']);
        $this->assertEquals(4, (float) DB::table('fin_stock_positions')->where('bin_id', $this->b->id)->sum('qty'));
    }

    public function test_labels_come_out_as_zpl_with_gs1_sscc_and_printers_must_be_on_the_private_network(): void
    {
        $o = $this->in(fn () => app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'LBL-1', [['item_id' => $this->item->id, 'qty' => 1]], ['ship_to' => ['name' => 'Ms Dube', 'city' => 'Durban']], $this->manager)[0]);
        $pkg = Package::create(['company_id' => $this->company->id, 'order_id' => $o->id, 'sscc' => '360012345000000017', 'status' => 'CLOSED', 'weight_kg' => 12.5]);

        $set = $this->in(fn () => app(LabelPrinting::class)->labels('PALLET', collect([$pkg])));
        $this->assertSame('Ms Dube, Durban', $set[0]['lines']['ship_to']);
        $zpl = $this->in(fn () => app(LabelPrinting::class)->zpl('PALLET', $set));
        $this->assertStringStartsWith('^XA', $zpl);
        $this->assertStringContainsString('>;>800360012345000000017', $zpl, 'The pallet barcode is not a GS1-128 SSCC.');

        $this->assertTrue(LabelPrinting::isPrivateAddress('192.168.10.40'));
        $this->assertFalse(LabelPrinting::isPrivateAddress('8.8.8.8'));
        $this->assertFalse(LabelPrinting::isPrivateAddress('127.0.0.1'));
        $this->assertFalse(LabelPrinting::isPrivateAddress('printer.example.com'));
        $this->actingAs($this->manager)->post(route('warehouse.labels.printers.store'), ['name' => 'Dock', 'host' => '8.8.8.8', 'port' => 9100, 'dpi' => 203])->assertSessionHasErrors('host');

        $this->actingAs($this->manager)->post(route('warehouse.labels.print'), ['kind' => 'BIN', 'ids' => [$this->a->uuid], 'output' => 'BROWSER'])->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Labels/Print')->where('labels.0.lines.code', 'F-01'));
        $this->actingAs($this->manager)->post(route('warehouse.labels.print'), ['kind' => 'PRODUCT', 'ids' => [$this->item->uuid], 'output' => 'ZPL'])->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="labels-product.zpl"');
    }

    public function test_the_floor_screens_and_sync_answer_by_permission(): void
    {
        $task = $this->wave();
        $this->actingAs($this->picker)->get(route('warehouse.mobile.floor'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Mobile/Floor'));
        $this->actingAs($this->picker)->getJson(route('warehouse.mobile.bundle'))->assertOk()->assertJsonPath('picks.0.id', $task->uuid);
        $this->actingAs($this->viewer)->postJson(route('warehouse.mobile.sync'), $this->action('PICK_CONFIRM', ['task' => $task->uuid, 'qty' => 1]))->assertForbidden();
        $this->actingAs($this->picker)->postJson(route('warehouse.mobile.sync'), $this->action('PICK_CONFIRM', ['task' => $task->uuid, 'qty' => 2, 'bin_scan' => 'F-01', 'item_scan' => 'CUP']))
            ->assertOk()->assertJsonPath('status', 'APPLIED');
        $this->actingAs($this->viewer)->get(route('warehouse.mobile.conflicts'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('warehouse.mobile.conflicts'))->assertOk();
        $this->actingAs($this->viewer)->get(route('warehouse.labels.setup'))->assertOk();
    }
}
