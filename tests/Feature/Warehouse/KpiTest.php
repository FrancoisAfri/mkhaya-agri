<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\Picking;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseKpis;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** §33: KPIs are computed exactly as defined, say when they cannot be, are stored once per month and compared with a baseline. */
class KpiTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('KP', ['is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'K1', 'pick_sequence' => 1]);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'KPI', 'name' => 'KPI item', 'uom' => 'ea', 'standard_cost' => 10]);
            app(StockLedger::class)->receive($this->item->fresh(), 20, 10, $this->wh, ['bin_id' => $this->bin->id]);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function pickedOrder(string $ref, float $qty, float $picked): int
    {
        return $this->in(function () use ($ref, $qty, $picked) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', $ref, [['item_id' => $this->item->id, 'qty' => $qty]], [], $this->manager);
            $o = app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o));
            app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->manager);
            foreach (PickTask::withoutGlobalScopes()->where('order_id', $o->id)->get() as $task) {
                app(Picking::class)->confirm($task, $picked, $this->manager, [], $picked < $qty);
            }

            return $o->id;
        });
    }

    public function test_kpis_follow_their_definitions_and_say_when_they_cannot_be_measured(): void
    {
        $a = $this->pickedOrder('K-1', 2, 2);
        $b = $this->pickedOrder('K-2', 4, 3);
        // Order A dispatched 3 hours after release and on time; B 9 hours after and late.
        DB::table('wms_orders')->where('id', $a)->update(['released_at' => now()->subHours(5), 'dispatched_at' => now()->subHours(2), 'sla_due_at' => now()->subHour()]);
        DB::table('wms_orders')->where('id', $b)->update(['released_at' => now()->subHours(10), 'dispatched_at' => now()->subHour(), 'sla_due_at' => now()->subHours(3)]);

        $k = $this->in(fn () => app(WarehouseKpis::class)->compute(now()->startOfMonth(), now()->endOfDay()));
        $this->assertSame(50.0, $k['picking_accuracy']['value']);
        $this->assertSame([1, 2], [$k['picking_accuracy']['numerator'], $k['picking_accuracy']['denominator']]);
        $this->assertSame(500.0, $k['replenishment_effectiveness']['value'], 'one short pick in two is 500 per 1,000');
        $this->assertSame(50.0, $k['on_time_dispatch']['value']);
        $this->assertSame(6.0, $k['order_cycle_time']['value'], 'the median of 3 and 9 hours');
        $this->assertNull($k['receiving_throughput']['value']);
        $this->assertStringContainsString('RECEIVE', $k['receiving_throughput']['note'], 'says why it cannot be measured');
        $this->assertSame(count(WarehouseKpis::DEFINITIONS), count($k));
    }

    public function test_receiving_throughput_uses_attendance_hours_shared_across_activities(): void
    {
        $clerk = $this->person('receiver', [RoleCatalog::VIEW, RoleCatalog::RECEIVE]);
        $this->in(fn () => WarehouseStaff::create(['company_id' => $this->company->id, 'warehouse_id' => $this->wh->id, 'user_id' => $clerk->id, 'activities' => ['RECEIVE', 'PUTAWAY'], 'is_active' => true]));
        $person = DB::table('hr_people')->insertGetId(['company_id' => $this->company->id, 'user_id' => $clerk->id, 'first_name' => 'Ayanda', 'surname' => 'Zulu', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendance_records')->insert(['company_id' => $this->company->id, 'employee_id' => $person, 'date' => now()->toDateString(), 'hours_worked' => 8, 'created_at' => now(), 'updated_at' => now()]);
        $supplier = DB::table('suppliers')->insertGetId(['company_name' => 'Supplier', 'company_id' => $this->company->id, 'uuid' => (string) Str::uuid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $po = DB::table('purchase_orders')->insertGetId(['company_id' => $this->company->id, 'supplier_id' => $supplier, 'po_number' => 'PO-K1', 'title' => 'K', 'expected_delivery' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $grn = DB::table('goods_received_notes')->insertGetId(['company_id' => $this->company->id, 'grn_number' => 'GRN-K1', 'purchase_order_id' => $po, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 6) as $i) {
            DB::table('goods_received_items')->insert(['company_id' => $this->company->id, 'goods_received_note_id' => $grn, 'accepted_qty' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $k = $this->in(fn () => app(WarehouseKpis::class)->compute(now()->startOfMonth(), now()->endOfDay()));
        $this->assertSame(1.5, $k['receiving_throughput']['value'], '6 lines over 4 receiving hours (8 hours shared by two activities)');
        $this->assertSame(100.0, $k['supplier_delivery_accuracy']['value'], 'on time, no quantity discrepancy');
    }

    public function test_a_month_is_recorded_once_and_measured_against_the_chosen_baseline(): void
    {
        $period = now()->subMonthNoOverflow()->format('Y-m');
        $this->actingAs($this->viewer)->get(route('warehouse.kpis'))->assertForbidden();
        $this->actingAs($this->manager)->post(route('warehouse.kpis.act'), ['action' => 'baseline', 'period' => $period])->assertStatus(422);
        $this->actingAs($this->manager)->post(route('warehouse.kpis.act'), ['action' => 'snapshot', 'period' => $period])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('warehouse.kpis.act'), ['action' => 'snapshot', 'period' => $period])->assertSessionHasNoErrors();
        $this->assertSame(count(WarehouseKpis::DEFINITIONS), DB::table('wms_kpi_snapshots')->where('company_id', $this->company->id)->where('period', $period)->count(), 'recorded once');
        $this->actingAs($this->manager)->post(route('warehouse.kpis.act'), ['action' => 'baseline', 'period' => $period])->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('wms_audit_events')->where('action', 'kpi.baseline_set')->where('entity_ref', $period)->exists());
        $this->actingAs($this->manager)->get(route('warehouse.kpis'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Insights/Kpis')->where('baseline', $period)->has('kpis', count(WarehouseKpis::DEFINITIONS)));
        $this->artisan('wms:kpi-snapshot', ['--company' => $this->company->id, '--period' => $period])->assertExitCode(0);
    }
}
