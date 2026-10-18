<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Forecast;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\PlanItem;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\PlanRecommendation;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Services\MaterialPlanner;
use App\Modules\Operations\Manufacturing\Services\PlanApproval;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MFG-004/005/015 -- MRP on a two-level product with known numbers, and the review that turns its
 * recommendations into drafts.
 *
 * A WIDGET is 2 kg STEEL and 1 BRACKET; a BRACKET is 0.5 kg STEEL and 2 BOLT. Steel is bought (14
 * days, safety stock 10); bolts are bought (minimum order 100, multiple 50). On hand: 30 kg steel,
 * 10 of it reserved for an open order still to draw 10; 20 bolts. 20 kg steel is on order for this week.
 *
 * Demand this month: a sales order for 20 widgets, and a forecast of 30 -- of which 20 is already
 * the sales order, so only 10 is planned. 5 widgets are still due from the open order.
 */
class PlanningTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private Item $bracket;

    private FinStockItem $steel;

    private FinStockItem $bolt;

    private int $supplierId;

    private $planner2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $role = Role::firstOrCreate(['name' => 'mfg test material planner', 'guard_name' => 'web']);
        $role->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_PLANNING, RoleCatalog::MANAGE_PLANNING, RoleCatalog::APPROVE_PLAN]);
        $this->planner2 = $this->makeUser('mfg-mrp@example.test', $role);
        $this->grantModuleTier($this->planner2);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, function () {
            Shift::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
                'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 0, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true]);
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_material_for_approval' => false, 'require_routing' => false]);
            $this->plant->update(['default_warehouse_id' => $this->store->id]);
        });

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->bolt = $this->stockItem('BOLT', 'ea', 1);
        $made = fn (string $code, string $kind) => $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $this->stockItem($code, 'ea', 0)->id, 'kind' => $kind,
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true,
        ]));
        $this->widget = $made('WIDGET', 'FINISHED');
        $this->bracket = $made('BRACKET', 'SUBASSEMBLY');
        $bracketStock = FinStockItem::withoutGlobalScopes()->find($this->bracket->stock_item_id);
        $this->approvedBom($this->bracket, [[$this->steel, 0.5, 0, 'kg'], [$this->bolt, 2, 0, 'ea']]);
        $this->approvedBom($this->widget, [[$this->steel, 2, 0, 'kg'], [$bracketStock, 1, 0, 'ea']]);

        $this->supplierId = (int) DB::table('suppliers')->insertGetId(['company_name' => 'Metals Co', 'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->asCompany($this->company, function () {
            PlanningParam::create(['company_id' => $this->company->id, 'stock_item_id' => $this->steel->id, 'make_or_buy' => 'BUY',
                'lead_time_days' => 14, 'safety_stock' => 10, 'preferred_supplier_id' => $this->supplierId]);
            PlanningParam::create(['company_id' => $this->company->id, 'stock_item_id' => $this->bolt->id, 'make_or_buy' => 'BUY',
                'lead_time_days' => 7, 'min_order_qty' => 100, 'order_multiple' => 50, 'preferred_supplier_id' => $this->supplierId]);

            $ledger = app(StockLedger::class);
            $ledger->receive($this->steel->fresh(), 30, 10, $this->store, ['reference' => 'GRN']);
            $ledger->receive($this->bolt->fresh(), 20, 1, $this->store, ['reference' => 'GRN']);

            // An open order for 5 widgets: 10 kg steel reserved and still to be drawn, 5 widgets due.
            $order = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => 5, 'due_date' => now()->addDays(3)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $order->components()->where('stock_item_id', '!=', $this->steel->id)->delete();
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->engineer);
            $life->approve($order, $this->approver);
            $life->release($order, $this->approver);
        });

        // 20 kg steel on order, due this week (in time for the first week's need, so the netting is plain arithmetic).
        $po = DB::table('purchase_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'po_number' => 'PO-9001',
            'supplier_id' => $this->supplierId, 'title' => 'Steel', 'status' => 'approved', 'order_date' => now()->subDays(3)->toDateString(),
            'expected_delivery' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_order_items')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'purchase_order_id' => $po,
            'name' => 'Steel', 'quantity' => 20, 'unit_price' => 9.5, 'line_total' => 190, 'received_qty' => 0, 'stock_item_id' => $this->steel->id,
            'created_at' => now(), 'updated_at' => now()]);

        // A confirmed sales order for 20 widgets, and an approved forecast of 30 for the month.
        $entity = $this->steel->fresh()->entity_id;
        $party = DB::table('fin_parties')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity,
            'kind' => 'customer', 'code' => 'C1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $finItem = DB::table('fin_items')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity,
            'code' => 'WIDGET', 'name' => 'Widget', 'created_at' => now(), 'updated_at' => now()]);
        $so = DB::table('fin_sales_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity,
            'party_id' => $party, 'number' => 'SO-1', 'order_date' => now()->toDateString(), 'currency' => 'ZAR', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('fin_sales_order_lines')->insert(['company_id' => $this->company->id, 'entity_id' => $entity, 'order_id' => $so, 'item_id' => $finItem,
            'description' => 'Widgets', 'quantity' => 20, 'unit_price' => 100, 'line_total' => 2000, 'qty_delivered' => 0,
            'account_id' => DB::table('fin_accounts')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $this->asCompany($this->company, fn () => Forecast::create(['company_id' => $this->company->id, 'stock_item_id' => $this->widget->stock_item_id,
            'period_start' => now()->startOfMonth()->toDateString(), 'qty' => 30, 'status' => 'APPROVED']));
    }

    private function runPlan()
    {
        return $this->asCompany($this->company, fn () => app(MaterialPlanner::class)->run($this->planner2, 8, $this->company->id));
    }

    private function line($run, $stockItemId): PlanItem
    {
        return PlanItem::withoutGlobalScopes()->where('run_id', $run->id)->where('stock_item_id', $stockItemId)->sole();
    }

    public function test_mrp_nets_every_level_without_double_counting_and_rounds_to_order_rules(): void
    {
        $run = $this->runPlan();

        $widget = $this->line($run, $this->widget->stock_item_id);
        $this->assertEquals(30.0, (float) $widget->gross, 'The forecast was not consumed by the confirmed order.');   // 20 ordered + 10 uncovered forecast
        $this->assertEquals(5.0, (float) $widget->scheduled_receipts);
        $this->assertEquals(25.0, (float) $widget->planned);
        $this->assertSame(0, $widget->level);
        $this->assertSame('MAKE', $widget->make_or_buy);

        $bracket = $this->line($run, $this->bracket->stock_item_id);
        $this->assertSame(1, $bracket->level);
        $this->assertEquals(25.0, (float) $bracket->gross);

        $steel = $this->line($run, $this->steel->id);
        $this->assertSame(2, $steel->level, 'Steel was planned before the bracket that uses it.');
        // 10 still to draw on the open order + 2 x 25 for widgets + 0.5 x 25 for brackets.
        $this->assertEquals(72.5, (float) $steel->gross);
        // On hand counts the reserved 10: the open order's draw is already demand.
        $this->assertEquals(30.0, (float) $steel->on_hand);
        $this->assertEquals(20.0, (float) $steel->scheduled_receipts);
        // 72.5 needed - 30 on hand - 20 on order + 10 safety stock = 32.5 to buy.
        $this->assertEquals(32.5, (float) $steel->planned);
        $this->assertNotEmpty($steel->exceptions, 'A late release for steel was not flagged.');

        $bolt = $this->line($run, $this->bolt->id);
        $this->assertEquals(50.0, (float) $bolt->gross);
        $boltRecs = PlanRecommendation::withoutGlobalScopes()->where('run_id', $run->id)->where('stock_item_id', $this->bolt->id)->get();
        $this->assertCount(1, $boltRecs);
        $this->assertEquals(100.0, (float) $boltRecs[0]->qty, 'The minimum order quantity was not applied.');
        // The first week's shortfall is what triggered it; the rounded order covers the rest of the horizon.
        $this->assertLessThan(100.0, (float) $boltRecs[0]->net_qty);
        $this->assertEquals(100.0, (float) $bolt->planned);
        $this->assertGreaterThanOrEqual(0.0, (float) $bolt->projected_end);

        // The pegging shows what the requirement is made of.
        $this->assertEqualsCanonicalizing(['SALES_ORDER', 'FORECAST'], collect($widget->demand)->pluck('type')->unique()->values()->all());

        // A forecast is never a sales commitment.
        $this->assertSame(1, DB::table('fin_sales_orders')->count());
    }

    public function test_approving_makes_drafts_one_purchase_request_per_supplier_and_records_overrides(): void
    {
        $run = $this->runPlan();
        $recs = PlanRecommendation::withoutGlobalScopes()->where('run_id', $run->id)->get();
        $purchases = $recs->where('kind', 'PURCHASE');
        $produce = $recs->where('kind', 'PRODUCE')->where('stock_item_id', $this->widget->stock_item_id)->first();

        // A planner changes the widget quantity, with a reason.
        $this->asCompany($this->company, fn () => app(PlanApproval::class)->override($produce, 30, null, 'Round up to a full pallet', $this->planner2));

        $made = $this->asCompany($this->company, fn () => app(PlanApproval::class)->approve($purchases->push($produce->fresh()), $this->planner2, $this->plant));

        $this->assertCount(1, $made['purchase_requests'], 'Purchases from the same supplier were not consolidated.');
        $request = PurchaseRequest::withoutGlobalScopes()->where('request_number', $made['purchase_requests'][0])->sole();
        $this->assertSame('draft', $request->status, 'MRP committed a purchase instead of drafting one.');
        $this->assertEqualsCanonicalizing([$this->steel->id, $this->bolt->id], $request->items()->pluck('stock_item_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, DB::table('purchase_orders')->where('po_number', '!=', 'PO-9001')->count(), 'A purchase order was placed.');

        $order = PO::withoutGlobalScopes()->where('number', $made['production_orders'][0])->sole();
        $this->assertSame(PO::DRAFT, $order->status);
        $this->assertEquals(30.0, (float) $order->planned_qty);
        $this->assertSame('MRP', $order->demand_type);

        $this->assertSame(PlanRecommendation::CONVERTED, $produce->fresh()->status);
        $this->assertSame('Round up to a full pallet', $produce->fresh()->decision_reason);

        // A newer run supersedes the old one: its recommendations can no longer be acted on.
        $this->runPlan();
        $stale = PlanRecommendation::withoutGlobalScopes()->where('run_id', $run->id)->where('status', 'PROPOSED')->first();
        if ($stale) {
            $this->expectException(\DomainException::class);
            $this->asCompany($this->company, fn () => app(PlanApproval::class)->approve(collect([$stale]), $this->planner2, $this->plant));
        }
    }

    public function test_the_planning_screens_and_who_may_do_what(): void
    {
        $cards = array_column($this->actingAs($this->planner2)->get('/manufacturing/planning')->assertOk()->viewData('page')['props']['cards'], 'path');
        $this->assertEqualsCanonicalizing(['/manufacturing/planning/demand', '/manufacturing/planning/mrp', '/manufacturing/planning/schedule', '/manufacturing/planning/suppliers', '/manufacturing/planning/parameters'], $cards);

        $demand = $this->actingAs($this->planner2)->get(route('manufacturing.planning.demand', ['rule' => 'PRIORITY']))->assertOk()->viewData('page');
        $widgetRow = collect($demand['props']['products'])->firstWhere('code', 'WIDGET');
        $this->assertEquals(30.0, $widgetRow['months'][0]['forecast']);
        $this->assertEquals(20.0, $widgetRow['months'][0]['orders']);
        $this->assertIsArray($demand['props']['capacity']);
        $this->assertSame(['planned', 'confirmed', 'released', 'completed', 'overdue', 'on_hold'], array_keys($demand['props']['status']));

        $this->actingAs($this->planner2)->post(route('manufacturing.planning.mrp.run'), ['horizon_weeks' => 8])->assertRedirect();
        $mrp = $this->actingAs($this->planner2)->get(route('manufacturing.planning.mrp'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Planning/Mrp', $mrp['component']);
        $this->assertTrue($mrp['props']['can']['decide']);
        $steelRec = collect($mrp['props']['recommendations'])->firstWhere('code', 'STEEL');
        $this->assertSame('Metals Co', $steelRec['supplier']);

        $this->actingAs($this->engineer)->post(route('manufacturing.planning.mrp.approve'), ['recommendations' => [$steelRec['id']]])->assertForbidden();
        $this->actingAs($this->planner2)->post(route('manufacturing.planning.mrp.decide', $steelRec['id']), ['action' => 'reject', 'reason' => 'Buying from stockist'])
            ->assertSessionHas('success');

        $suppliers = $this->actingAs($this->planner2)->get(route('manufacturing.planning.suppliers', ['item' => $this->steel->id]))->assertOk()->viewData('page');
        $this->assertEquals(9.5, $suppliers['props']['item']['prices'][0]['last_price']);

        $this->actingAs($this->planner2)->post(route('manufacturing.planning.parameters.save', $this->bolt->id), ['make_or_buy' => 'BUY', 'lead_time_days' => 5, 'min_order_qty' => 200])
            ->assertSessionHas('success');
        $this->assertEquals(200.0, (float) PlanningParam::withoutGlobalScopes()->where('stock_item_id', $this->bolt->id)->value('min_order_qty'));

        $this->actingAs($this->planner2)->post(route('manufacturing.planning.forecasts.store'), ['stock_item_id' => $this->widget->stock_item_id,
            'period' => now()->addMonth()->format('Y-m'), 'qty' => 40])->assertSessionHas('success');
        $this->assertSame('DRAFT', Forecast::withoutGlobalScopes()->latest('id')->value('status'));
        $this->actingAs($this->outsider)->get(route('manufacturing.planning.mrp'))->assertForbidden();
    }
}
