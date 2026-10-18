<?php

namespace Tests\Feature\Warehouse;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LabourPlanner;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\Picking;
use App\Modules\Operations\WarehouseManagement\Services\PickAdvisor;
use App\Modules\Operations\WarehouseManagement\Services\Productivity;
use App\Modules\Operations\WarehouseManagement\Services\PutawayTasks;
use App\Modules\Operations\WarehouseManagement\Services\Simulator;
use App\Modules\Operations\WarehouseManagement\Services\Slotting;
use App\Modules\Operations\WarehouseManagement\Services\TaskBoard;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** WMS-130..135: one task board, labour from real timings, picking and slotting advice, fair metrics, reproducible simulation. */
class WorkTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $near;

    private FinStockBin $far;

    private FinStockBin $empty;

    private User $picker;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('WRK', ['is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $loc = app(LocationService::class);
            $this->near = $loc->create($this->wh, ['code' => 'A-01', 'pick_sequence' => 1]);
            $this->empty = $loc->create($this->wh, ['code' => 'A-02', 'pick_sequence' => 2]);
            $this->far = $loc->create($this->wh, ['code' => 'Z-99', 'pick_sequence' => 99]);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SOAP', 'name' => 'Soap', 'uom' => 'ea', 'standard_cost' => 5]);
            app(StockLedger::class)->receive($this->item->fresh(), 100, 5, $this->wh, ['bin_id' => $this->far->id]);
        });
        $this->picker = $this->person('picker', [RoleCatalog::VIEW, RoleCatalog::PICK]);
        $this->approver = $this->person('approver', [RoleCatalog::VIEW, RoleCatalog::APPROVE_SLOTTING]);
        DB::table('wms_warehouse_staff')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'warehouse_id' => $this->wh->id,
            'user_id' => $this->picker->id, 'activities' => json_encode(['PICK', 'PACK']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function order(float $qty, string $ref, array $attrs = []): FulfilmentOrder
    {
        return $this->in(function () use ($qty, $ref, $attrs) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', $ref, [['item_id' => $this->item->id, 'qty' => $qty]], $attrs + ['customer_name' => 'C'], $this->manager);

            return app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o));
        });
    }

    public function test_the_board_shows_all_work_with_dependencies_and_keeps_assignment_history(): void
    {
        $putaway = $this->in(fn () => app(PutawayTasks::class)->create($this->wh, $this->item, 5, ['from_bin_id' => $this->far->id]));
        $o = $this->order(2, 'BRD-1');
        $this->in(fn () => app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->manager));
        // A replenishment into the bin the wave picks from: the wave waits for it.
        DB::table('wms_replenishment_tasks')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'number' => 'RPL-T1', 'warehouse_id' => $this->wh->id,
            'item_id' => $this->item->id, 'from_bin_id' => $this->empty->id, 'to_bin_id' => $this->far->id, 'qty' => 5, 'priority' => 70, 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);

        $tasks = collect($this->in(fn () => app(TaskBoard::class)->queue($this->wh)));
        $this->assertEqualsCanonicalizing(['PUTAWAY', 'PICK', 'REPLENISH'], $tasks->pluck('type')->unique()->values()->all());
        $pick = $tasks->firstWhere('type', 'PICK');
        $this->assertSame('BLOCKED', $pick['state']);
        $this->assertStringContainsString('RPL-T1', $pick['waits_for'][0]);

        $this->in(fn () => app(TaskBoard::class)->assign('PUTAWAY', $putaway->uuid, $this->picker, 'HIGH', now()->subMinute()->toIso8601String(), $this->manager));
        $this->in(fn () => app(TaskBoard::class)->assign('PUTAWAY', $putaway->uuid, $this->manager, null, null, $this->manager, 'Picker off sick'));
        $this->assertSame($this->manager->id, $putaway->fresh()->assigned_to);
        $this->assertCount(2, $this->in(fn () => app(TaskBoard::class)->history('PUTAWAY', $putaway->uuid)), 'Reassigning erased the first assignment.');

        $this->in(fn () => app(TaskBoard::class)->assign('PICK', $pick['ref'], $this->picker, null, now()->subHour()->toIso8601String(), $this->manager));
        $mine = $this->in(fn () => app(TaskBoard::class)->queue($this->wh, null, $this->picker->id));
        $this->assertCount(1, $mine);
        $this->assertTrue($mine[0]['overdue']);
        $this->assertSame('URGENT', $mine[0]['priority']);
    }

    public function test_labour_standards_come_from_real_timings_and_staffing_is_planned_by_skill(): void
    {
        foreach (range(1, 12) as $i) {
            $t = $this->in(fn () => app(PutawayTasks::class)->create($this->wh, $this->item, 1, ['from_bin_id' => $this->far->id]));
            DB::table('wms_putaway_tasks')->where('id', $t->id)->update(['status' => 'DONE', 'started_at' => now()->subMinutes(10), 'completed_at' => now()->subMinutes(4)]);
        }
        $std = $this->in(fn () => app(LabourPlanner::class)->remeasure($this->wh));
        $this->assertSame('MEASURED', $std['PUTAWAY']['source']);
        $this->assertEqualsWithDelta(6, $std['PUTAWAY']['minutes_per_task'], 0.1);
        $this->assertSame('SET', $std['PICK']['source'], 'A standard was "measured" from too few timings.');

        $plan = collect($this->in(fn () => app(LabourPlanner::class)->plan($this->wh))['activities'])->keyBy('activity');
        $this->assertSame(1, $plan['PICK']['people_rostered']);
        $this->assertSame(0, $plan['PUTAWAY']['people_rostered'], 'The picker was counted for a skill they do not have.');
    }

    public function test_picking_advice_batches_nearby_orders_and_sends_urgent_ones_first(): void
    {
        $this->order(1, 'P-1');
        $this->order(1, 'P-2');
        $this->order(1, 'P-URGENT', ['required_by' => now()->addMinutes(30)]);

        $advice = $this->in(fn () => app(PickAdvisor::class)->advise($this->wh));
        $this->assertSame('DISCRETE', $advice['waves'][0]['strategy']);
        $this->assertSame('P-URGENT', DB::table('wms_orders')->where('uuid', $advice['waves'][0]['orders'][0]['id'])->value('external_ref'));
        $this->assertSame('BATCH', $advice['waves'][1]['strategy']);
        $this->assertCount(2, $advice['waves'][1]['orders']);
    }

    public function test_slotting_moves_a_fast_mover_closer_only_after_somebody_else_approves(): void
    {
        foreach (['S-1', 'S-2', 'S-3'] as $ref) {
            $o = $this->order(1, $ref);
            $this->in(fn () => app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->manager));
        }
        $uuid = $this->in(fn () => app(Slotting::class)->propose($this->wh, $this->manager));
        $moves = json_decode(DB::table('wms_slotting_proposals')->where('uuid', $uuid)->value('moves'), true);
        $this->assertSame('Z-99', $moves[0]['from']);
        $this->assertSame('A-01', $moves[0]['to'], 'The move did not go to the nearest free place.');

        try {
            $this->in(fn () => app(Slotting::class)->decide($uuid, true, null, $this->manager));
            $this->fail('The proposer approved their own slotting change.');
        } catch (RuntimeException) {
        }
        $sim = $this->in(fn () => app(Slotting::class)->simulate($uuid, $this->manager));
        $this->assertGreaterThan(0, $sim['results']['saving_pct']);
        $this->in(fn () => app(Slotting::class)->decide($uuid, true, 'Fine', $this->approver));
        $this->assertSame(1, $this->in(fn () => app(Slotting::class)->apply($uuid, $this->manager)));
        $task = PutawayTask::query()->where('source_type', 'SLOTTING')->firstOrFail();
        $this->assertSame($this->near->id, $task->suggested_bin_id);
    }

    public function test_a_simulation_repeats_exactly_from_its_seed_and_shows_its_range(): void
    {
        $this->in(function () {
            foreach (range(1, 20) as $i) {
                app(PutawayTasks::class)->create($this->wh, $this->item, 1, ['from_bin_id' => $this->far->id]);
            }
        });
        $a = $this->in(fn () => app(Simulator::class)->staffing($this->wh, ['staff' => ['PUTAWAY' => 1], 'trials' => 200, 'seed' => 4242], $this->manager));
        $b = $this->in(fn () => app(Simulator::class)->staffing($this->wh, ['staff' => ['PUTAWAY' => 1], 'trials' => 200, 'seed' => 4242], $this->manager));
        $this->assertSame($a['results'], $b['results'], 'The same seed gave a different result.');
        $this->assertArrayHasKey('p90', $a['results']['PUTAWAY']['work_hours']);
        $this->assertNotEmpty($a['assumptions']);

        $entity = DB::table('fin_stock_items')->where('id', $this->item->id)->value('entity_id');
        foreach (range(1, 60) as $d) {
            DB::table('fin_stock_movements')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity, 'item_id' => $this->item->id,
                'warehouse_id' => $this->wh->id, 'type' => 'dispatch', 'qty' => -3, 'value' => -15, 'movement_date' => now()->subDays($d)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $tight = $this->in(fn () => app(Simulator::class)->replenishment($this->wh, ['item_id' => $this->item->id, 'min' => 1, 'max' => 10, 'lead_days' => 7, 'trials' => 200, 'seed' => 7], $this->manager));
        $ample = $this->in(fn () => app(Simulator::class)->replenishment($this->wh, ['item_id' => $this->item->id, 'min' => 40, 'max' => 120, 'lead_days' => 7, 'trials' => 200, 'seed' => 7], $this->manager));
        $this->assertGreaterThan($ample['results']['chance_of_any_stockout'], $tight['results']['chance_of_any_stockout']);
    }

    public function test_performance_is_by_process_and_the_work_screens_answer(): void
    {
        $o = $this->order(2, 'PERF-1');
        $this->in(fn () => app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->manager));
        $report = $this->in(fn () => app(Productivity::class)->report($this->wh, now()->subDay()->toDateString(), now()->toDateString()));
        $this->assertArrayNotHasKey('by_person', $report);
        $this->assertNotEmpty($report['queues']);

        foreach (['board', 'labour', 'slotting', 'performance', 'simulations'] as $page) {
            $this->actingAs($this->viewer)->get(route("warehouse.work.{$page}"))->assertOk();
        }
        $this->actingAs($this->viewer)->post(route('warehouse.work.assign'), ['type' => 'PICK', 'ref' => 'x'])->assertForbidden();
        $this->actingAs($this->picker)->post(route('warehouse.work.simulate'), ['kind' => 'STAFFING', 'warehouse' => $this->wh->uuid])->assertForbidden();
    }
}
