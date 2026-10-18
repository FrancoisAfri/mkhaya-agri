<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Services\BarcodeRegistry;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\PutawayAdvisor;
use App\Modules\Operations\WarehouseManagement\Services\PutawayTasks;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use RuntimeException;

/**
 * WMS-040 to WMS-042: where stock should go, getting it there, and how full the place is.
 *
 * Each advisor rule is tested by building a floor where exactly one bin breaks it, then asserting
 * that bin is not chosen and the reason it was ruled out is the one the rule gives.
 */
class PutawayTest extends WarehouseTestCase
{
    private FinWarehouse $store;

    private FinStockBin $dock;

    private FinStockItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->store = $this->warehouse('PW', ['policies' => ['scan_to_confirm' => false, 'putaway' => 'SUGGESTED', 'rotation' => 'FEFO']]);
        $this->item = $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $this->dock = app(LocationService::class)->create($this->store, ['code' => 'DOCK', 'kind' => 'RECEIVING']);

            return app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'VAX-1', 'name' => 'Vaccine', 'uom' => 'ea']);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
    }

    private function bin(string $code, array $attrs = []): FinStockBin
    {
        return $this->asCompany($this->company, fn () => app(LocationService::class)->create($this->store, ['code' => $code] + $attrs));
    }

    private function suggest(float $qty = 10, ?int $lotId = null): array
    {
        return $this->asCompany($this->company, fn () => app(PutawayAdvisor::class)->suggest($this->store->fresh(), $this->item->fresh(), $qty, $lotId));
    }

    private function onDock(float $qty, ?int $lotId = null): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->item->fresh(), $qty, 5, $this->store, ['bin_id' => $this->dock->id, 'lot_id' => $lotId]));
    }

    // ── WMS-040 rules ────────────────────────────────────────────────────────────────────────

    public function test_a_cold_item_only_goes_into_a_bin_kept_at_its_temperature(): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item, ['temperature_min_c' => 2, 'temperature_max_c' => 8]));
        $this->bin('AMBIENT');
        $this->bin('FREEZER', ['requires_temperature_monitoring' => true, 'temperature_min_c' => -25, 'temperature_max_c' => -18]);
        $this->bin('FRIDGE', ['requires_temperature_monitoring' => true, 'temperature_min_c' => 2, 'temperature_max_c' => 8]);

        $s = $this->suggest();

        $this->assertSame('FRIDGE', $s['bin']->code);
        $this->assertSame('not temperature controlled', $s['rejected']['AMBIENT']);
        $this->assertStringContainsString('the item needs 2', $s['rejected']['FREEZER']);
    }

    public function test_hazardous_goods_need_a_cleared_bin_and_capacity_is_respected(): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item, ['hazard_class' => '3']));
        $this->bin('PLAIN');
        $this->bin('HAZ-SMALL', ['hazmat_allowed' => true, 'max_units' => 5]);
        $this->bin('HAZ-BIG', ['hazmat_allowed' => true, 'max_units' => 50]);

        $s = $this->suggest(10);

        $this->assertSame('HAZ-BIG', $s['bin']->code);
        $this->assertSame('not cleared for hazardous goods', $s['rejected']['PLAIN']);
        $this->assertStringContainsString('not enough room', $s['rejected']['HAZ-SMALL']);
    }

    public function test_a_bin_holding_another_lot_is_ruled_out_and_the_same_lot_consolidates(): void
    {
        $lotA = $this->asCompany($this->company, fn () => app(StockLedger::class)->lot($this->item->fresh(), 'A', ['expires_on' => now()->addMonths(3)->toDateString()]));
        $lotB = $this->asCompany($this->company, fn () => app(StockLedger::class)->lot($this->item->fresh(), 'B', ['expires_on' => now()->addMonths(6)->toDateString()]));
        $x = $this->bin('X');
        $y = $this->bin('Y');
        $this->bin('Z');
        $this->asCompany($this->company, function () use ($lotA, $lotB, $x, $y) {
            app(StockLedger::class)->receive($this->item->fresh(), 5, 5, $this->store, ['bin_id' => $x->id, 'lot_id' => $lotA->id]);
            app(StockLedger::class)->receive($this->item->fresh(), 5, 5, $this->store, ['bin_id' => $y->id, 'lot_id' => $lotB->id]);
        });

        $s = $this->suggest(3, $lotA->id);

        $this->assertSame('X', $s['bin']->code, 'The same lot was not consolidated.');
        $this->assertStringContainsString('another lot', $s['rejected']['Y']);
    }

    public function test_a_fast_mover_goes_near_the_front_and_a_slow_one_to_the_back(): void
    {
        $this->bin('FRONT', ['pick_sequence' => 10]);
        $this->bin('BACK', ['pick_sequence' => 4900]);

        $this->assertSame('BACK', $this->suggest()['bin']->code, 'A slow mover took the pick face.');

        // Twenty sales out of this warehouse in the last month make it a fast mover.
        $this->onDock(100);
        foreach (range(1, PutawayAdvisor::FAST_MOVER_PICKS) as $i) {
            $this->asCompany($this->company, fn () => app(\App\Modules\Finance\Accounting\Services\InventoryService::class)->sell($this->item->fresh(), 1, null, "INV-{$i}", $this->store));
        }

        $this->assertSame('FRONT', $this->suggest()['bin']->code, 'A fast mover was buried at the back.');
    }

    // ── WMS-041 guided put-away ──────────────────────────────────────────────────────────────

    private function task(float $qty = 10): PutawayTask
    {
        $this->onDock($qty);

        return $this->asCompany($this->company, fn () => app(PutawayTasks::class)->create($this->store->fresh(), $this->item->fresh(), $qty, ['from_bin_id' => $this->dock->id, 'source_type' => 'TEST']));
    }

    public function test_scans_must_match_the_item_and_the_bin(): void
    {
        $this->asCompany($this->company, fn () => app(WarehouseDirectory::class)->save(['policies' => ['scan_to_confirm' => true]], $this->store));
        $shelf = $this->bin('S-1');
        $other = $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'OTHER', 'name' => 'Other', 'uom' => 'ea']));
        $task = $this->task();
        $svc = app(PutawayTasks::class);

        foreach ([
            [[], 'confirms every task by scan'],
            [['item_scan' => 'OTHER', 'bin_scan' => $shelf->barcode], 'scanned item is OTHER'],
            [['item_scan' => 'VAX-1', 'bin_scan' => 'PW.DOCK'], 'scanned bin is not S-1'],
        ] as [$scans, $msg]) {
            try {
                $this->asCompany($this->company, fn () => $svc->complete($task->fresh(), $shelf, 10, $this->manager, $scans));
                $this->fail("Put-away accepted: {$msg}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }

        $done = $this->asCompany($this->company, fn () => $svc->complete($task->fresh(), $shelf, 10, $this->manager, ['item_scan' => 'VAX-1', 'bin_scan' => $shelf->barcode]));
        $this->assertSame('DONE', $done->status);
    }

    public function test_a_directed_warehouse_only_accepts_the_suggested_bin_unless_overridden(): void
    {
        $this->asCompany($this->company, fn () => app(WarehouseDirectory::class)->save(['policies' => ['putaway' => 'DIRECTED']], $this->store));
        $this->bin('S-1', ['pick_sequence' => 4000]);
        $alt = $this->bin('S-2', ['pick_sequence' => 10]);
        $task = $this->task();
        $this->assertNotSame($alt->id, $task->suggested_bin_id);
        $clerk = $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::MOVE_STOCK]);

        try {
            $this->asCompany($this->company, fn () => app(PutawayTasks::class)->complete($task, $alt, 10, $clerk));
            $this->fail('A directed put-away went to another bin.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('directs put-away', $e->getMessage());
        }

        $done = $this->asCompany($this->company, fn () => app(PutawayTasks::class)->complete($task->fresh(), $alt, 10, $this->manager));
        $this->assertSame($alt->id, $done->to_bin_id, 'A supervisor could not override.');
    }

    public function test_a_partial_put_away_waits_in_the_exception_queue_and_can_be_finished(): void
    {
        $shelf = $this->bin('S-1');
        $task = $this->task(10);

        $t = $this->asCompany($this->company, fn () => app(PutawayTasks::class)->complete($task, $shelf, 4, $this->manager));
        $this->assertSame('PARTIAL', $t->status);
        $this->actingAs($this->manager)->get(route('warehouse.floor.putaway.index', ['view' => 'exceptions']))
            ->assertOk()->assertInertia(fn ($p) => $p->where('tasks.data.0.number', $task->number));

        $t = $this->asCompany($this->company, fn () => app(PutawayTasks::class)->complete($t->fresh(), $shelf, 6, $this->manager));
        $this->assertSame('DONE', $t->status);
        $this->assertEquals(10, (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $shelf->id)->sum('qty'));
    }

    public function test_a_failed_task_needs_a_reason_and_only_assigned_staff_may_work_a_staffed_warehouse(): void
    {
        $shelf = $this->bin('S-1');
        $task = $this->task();

        try {
            app(PutawayTasks::class)->fail($task, $this->manager, ' ');
            $this->fail('A task failed without a reason.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->asCompany($this->company, fn () => app(WarehouseDirectory::class)->assignStaff($this->store, $this->supervisor->id, ['PICK']));
        $this->expectExceptionMessage('not assigned to put away');
        $this->asCompany($this->company, fn () => app(PutawayTasks::class)->complete($task->fresh(), $shelf, 10, $this->manager));
    }

    public function test_every_put_away_screen_renders_through_the_routes(): void
    {
        $shelf = $this->bin('S-1', ['max_units' => 10]);
        $task = $this->task(9);
        $this->actingAs($this->manager);

        $this->get(route('warehouse.floor.putaway.show', $task->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Putaway/Show')
            ->where('task.suggested', 'S-1')->has('task.reasons.chosen'));
        $this->post(route('warehouse.floor.putaway.act', $task->uuid), ['action' => 'complete', 'bin' => $shelf->uuid, 'qty' => 9])->assertSessionHasNoErrors();
        $this->assertSame('DONE', $task->fresh()->status);

        $this->get(route('warehouse.floor.capacity'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Putaway/Capacity')
            ->where('warehouses.0.near_full', 1));
        $this->get(route('warehouse.hub.floor'))->assertOk();
        foreach (['Index', 'Show', 'Capacity'] as $page) {
            $this->assertFileExists(resource_path("js/Modules/Warehouse/Putaway/{$page}.vue"));
        }
    }
}
