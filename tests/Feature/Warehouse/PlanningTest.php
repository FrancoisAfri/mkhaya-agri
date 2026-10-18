<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Forecast;
use App\Modules\Operations\WarehouseManagement\Services\DemandForecaster;
use App\Modules\Operations\WarehouseManagement\Services\ExcessObsolete;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\PurchasingAdvisor;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** WMS-120..124: forecasts that know their limits, explained recommendations, excess stock, approval-bound buying. */
class PlanningTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('PLN');
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'R1']);
        });
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function item(string $code, float $cost = 10): FinStockItem
    {
        return $this->in(fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => $code, 'name' => $code, 'uom' => 'ea', 'standard_cost' => $cost]));
    }

    /** History of what left, month by month (oldest first), written straight into the ledger's table. */
    private function history(FinStockItem $item, array $monthly): void
    {
        $entity = DB::table('fin_stock_items')->where('id', $item->id)->value('entity_id');
        foreach (array_reverse($monthly) as $i => $qty) {
            if ($qty <= 0) {
                continue;
            }
            DB::table('fin_stock_movements')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity, 'item_id' => $item->id,
                'warehouse_id' => $this->wh->id, 'type' => 'dispatch', 'qty' => -$qty, 'unit_cost' => 10, 'value' => -$qty * 10,
                'movement_date' => now()->startOfMonth()->subMonthsNoOverflow($i + 1)->addDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_methods_follow_the_demand_and_too_little_history_gives_no_number(): void
    {
        $steady = $this->item('STEADY');
        $this->history($steady, array_fill(0, 12, 100));
        $lumpy = $this->item('LUMPY');
        $this->history($lumpy, [0, 0, 30, 0, 0, 0, 25, 0, 0, 0, 35, 0]);
        $thin = $this->item('THIN');
        $this->history($thin, [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5, 5]);
        $spike = $this->item('SPIKE');
        $this->history($spike, [100, 100, 100, 100, 2000, 100, 100, 100, 100, 100, 100, 100]);

        $run = $this->in(fn () => app(DemandForecaster::class)->run(['bucket' => 'MONTH', 'horizon' => 3, 'history' => 12,
            'item_ids' => [$steady->id, $lumpy->id, $thin->id, $spike->id]], $this->manager));
        $first = fn ($item) => Forecast::query()->where('run_id', $run->id)->where('item_id', $item->id)->orderBy('period_start')->first();

        $this->assertSame('SMOOTHING', $first($steady)->method);
        $this->assertEqualsWithDelta(100, (float) $first($steady)->qty, 0.5);
        $this->assertSame('CROSTON', $first($lumpy)->method, 'Intermittent demand was smoothed as if it were regular.');
        $this->assertSame('INSUFFICIENT', $first($thin)->method);
        $this->assertNull($first($thin)->qty, 'A forecast was given where the history cannot support one (QA-024).');
        $this->assertStringContainsString('at least 3', $first($thin)->basis['reason']);
        $this->assertCount(1, $first($spike)->basis['one_offs']);
        $this->assertEqualsWithDelta(100, (float) $first($spike)->qty, 1, 'A one-off order was learnt as normal demand.');
        $this->assertSame(1, $run->insufficient);
    }

    public function test_an_override_keeps_both_numbers_and_needs_a_reason(): void
    {
        $item = $this->item('PROMO');
        $this->history($item, array_fill(0, 6, 50));
        $run = $this->in(fn () => app(DemandForecaster::class)->run(['horizon' => 2, 'history' => 6, 'item_ids' => [$item->id]], $this->manager));
        $f = Forecast::query()->where('run_id', $run->id)->orderBy('period_start')->firstOrFail();

        try {
            $this->in(fn () => app(DemandForecaster::class)->override($f, 200, '', $this->manager));
            $this->fail('An override was accepted without a reason.');
        } catch (\RuntimeException) {
        }
        $this->in(fn () => app(DemandForecaster::class)->override($f, 200, 'Easter promotion', $this->manager));
        $f = $f->fresh();
        $this->assertEquals(50, (float) $f->qty, 'The original forecast was overwritten.');
        $this->assertEquals(200, $f->effective());
    }

    public function test_a_recommendation_uses_the_forecast_and_shows_every_input_and_supplier_terms(): void
    {
        $item = $this->item('BOLT', 2);
        $this->history($item, array_fill(0, 6, 300)); // about ten a day
        $this->in(fn () => app(DemandForecaster::class)->run(['horizon' => 3, 'history' => 6, 'item_ids' => [$item->id]], $this->manager));
        $this->in(fn () => app(StockLedger::class)->receive($item->fresh(), 50, 2, $this->wh, ['bin_id' => $this->bin->id]));
        $supplier = DB::table('suppliers')->insertGetId(['company_id' => $this->company->id, 'company_name' => 'Fasteners SA', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wms_supplier_terms')->insert(['company_id' => $this->company->id, 'supplier_id' => $supplier, 'stock_item_id' => $item->id,
            'min_order_qty' => 500, 'order_multiple' => 100, 'lead_time_days' => 14, 'unit_price' => 1.8, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wms_item_profiles')->updateOrInsert(['stock_item_id' => $item->id], ['company_id' => $this->company->id, 'safety_stock' => 40]);

        $rec = collect($this->in(fn () => app(PurchasingAdvisor::class)->recommendations()))->firstWhere('code', 'BOLT');
        $this->assertNotNull($rec);
        $inputs = collect($rec['inputs'])->keyBy('label');
        $this->assertStringContainsString('Forecast', $inputs['Daily demand']['source']);
        $this->assertSame(14, $inputs['Lead time (days)']['value']);
        $this->assertStringContainsString('Fasteners SA', $inputs['Lead time (days)']['source']);
        $this->assertEquals(0, fmod($rec['suggested_qty'], 100), 'The order ignores the supplier\'s multiple.');
        $this->assertGreaterThanOrEqual(500, $rec['suggested_qty']);
        $this->assertSame('Fasteners SA', $rec['supplier']);
    }

    public function test_stock_that_does_not_move_is_classed_valued_and_given_somewhere_to_go(): void
    {
        $item = $this->item('WIDGET', 20);
        $this->in(fn () => app(StockLedger::class)->receive($item->fresh(), 40, 20, $this->wh, ['bin_id' => $this->bin->id]));
        DB::table('fin_stock_items')->where('id', $item->id)->update(['created_at' => now()->subDays(400)]);
        $other = $this->warehouse('PL2');
        $entity = DB::table('fin_stock_items')->where('id', $item->id)->value('entity_id');
        $binTwo = $this->in(fn () => app(LocationService::class)->create($other, ['code' => 'X1']));
        $this->in(fn () => app(StockLedger::class)->receive($item->fresh(), 2, 20, $other, ['bin_id' => $binTwo->id]));
        DB::table('fin_stock_movements')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity, 'item_id' => $item->id,
            'warehouse_id' => $other->id, 'type' => 'dispatch', 'qty' => -6, 'value' => -120, 'movement_date' => now()->subDays(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $a = $this->in(fn () => app(ExcessObsolete::class)->analyse());
        $row = collect($a['rows'])->firstWhere('warehouse', 'PLN');
        $this->assertSame('OBSOLETE', $row['class']);
        $this->assertEquals(800, $row['excess_value']);
        $this->assertSame('TRANSFER', $row['actions'][0]['kind']);
        $this->assertStringContainsString('PL2', $row['actions'][0]['text']);
    }

    public function test_the_planning_screens_and_a_draft_request_that_goes_to_procurement(): void
    {
        $item = $this->item('NUT', 1);
        DB::table('fin_stock_items')->where('id', $item->id)->update(['reorder_level' => 100]);

        $this->actingAs($this->viewer)->get(route('warehouse.planning.forecast'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Planning/Forecast'));
        $this->actingAs($this->viewer)->get(route('warehouse.planning.excess'))->assertOk();
        $this->actingAs($this->viewer)->post(route('warehouse.planning.forecast.run'), ['bucket' => 'MONTH', 'horizon' => 3])->assertForbidden();
        $this->actingAs($this->manager)->get(route('warehouse.planning.buying'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('recommendations.0.code', 'NUT'));
        $this->actingAs($this->manager)->post(route('warehouse.planning.buying.request'), ['lines' => [$item->uuid => 150]])->assertSessionHasNoErrors();

        $pr = DB::table('purchase_requests')->latest('id')->first();
        $this->assertSame('draft', $pr->status, 'A recommendation skipped the purchasing approvals.');
        $this->assertNull(DB::table('purchase_orders')->where('company_id', $this->company->id)->first(), 'A purchase order was created without approval.');
    }
}
