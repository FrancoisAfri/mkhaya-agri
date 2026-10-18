<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinSalesOrderLine;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\SalesOrderService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\CustomerCommitment;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Services\CustomerOrders;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MFG-017, the sales side: a sales order line becomes production and held stock, its progress is
 * tracked against the customer's date, and manufacturing lets go as Finance delivers -- without
 * ever writing to the sales order or invoicing it.
 */
class CustomerOrdersTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private FinStockItem $wood;

    private $sales;

    private int $entity;

    private int $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $role = Role::firstOrCreate(['name' => 'mfg test sales planner', 'guard_name' => 'web']);
        $role->syncPermissions(RoleCatalog::all());
        $this->sales = $this->makeUser('sales-planner@example.test', $role);
        $this->grantModuleTier($this->sales);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('FG');
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)
            ->update(['require_material_for_approval' => false, 'require_routing' => false, 'require_qc_before_putaway' => false]));
        $this->wood = $this->stockItem('WOOD', 'kg', 5);
        $this->entity = (int) $this->wood->fresh()->entity_id;
        $this->party = DB::table('fin_parties')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $this->entity,
            'kind' => 'customer', 'code' => 'C'.Str::random(4), 'name' => 'Acme Furniture', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function make(string $code, string $strategy): Item
    {
        $item = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem($code, 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id,
            'is_active' => true, 'production_strategy' => $strategy]));
        $this->approvedBom($item, [[$this->wood, 2, 0, 'kg']]);
        $this->routing($item, [['Build', 0, 30]], [], true);

        return $item;
    }

    private function salesLine(string $code, float $qty, string $description = 'As ordered'): FinSalesOrderLine
    {
        $finItem = DB::table('fin_items')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $this->entity,
            'code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]);
        $so = DB::table('fin_sales_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $this->entity,
            'party_id' => $this->party, 'number' => 'SO-'.Str::random(5), 'order_date' => now()->toDateString(), 'currency' => 'ZAR', 'status' => 'open',
            'customer_ref' => 'PO-ACME-9', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $line = DB::table('fin_sales_order_lines')->insertGetId(['company_id' => $this->company->id, 'entity_id' => $this->entity, 'order_id' => $so,
            'item_id' => $finItem, 'description' => $description, 'quantity' => $qty, 'unit_price' => 900, 'line_total' => $qty * 900, 'qty_delivered' => 0,
            'account_id' => DB::table('fin_accounts')->value('id'), 'created_at' => now(), 'updated_at' => now()]);

        return $this->asCompany($this->company, fn () => FinSalesOrderLine::query()->findOrFail($line));
    }

    private function commit(FinSalesOrderLine $line, array $extra = [])
    {
        return $this->actingAs($this->sales)->post(route('manufacturing.fulfilment.customer-orders.commit'), $extra + [
            'line_id' => $line->id, 'factory_id' => $this->plant->id, 'warehouse_id' => $this->store->id,
            'required_date' => now()->addDays(10)->toDateString(),
        ]);
    }

    private function held(int $lineId): float
    {
        return (float) FinStockReservation::withoutGlobalScopes()->where('source_type', 'SALES_ORDER_LINE')->where('source_id', $lineId)
            ->where('status', 'OPEN')->sum(DB::raw('qty - qty_consumed'));
    }

    private function receive(Item $item, float $qty): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive(FinStockItem::query()->findOrFail($item->stock_item_id), $qty, 300, $this->store, ['reference' => 'OPENING']));
    }

    public function test_a_made_to_order_line_holds_stock_and_gets_one_order_for_the_rest_without_touching_the_sales_order(): void
    {
        $chair = $this->make('CHAIR', 'MTO');
        $this->receive($chair, 3);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->wood->fresh(), 100, 5, $this->store, ['reference' => 'GRN']));
        $line = $this->salesLine('CHAIR', 10);
        $soBefore = (array) DB::table('fin_sales_orders')->where('id', $line->order_id)->first();
        $invoices = DB::table('fin_invoices')->count();

        $this->commit($line)->assertSessionHas('success');
        $this->commit($line)->assertSessionHas('success');   // committing again tops up; it never doubles

        $this->assertEquals(3.0, $this->held($line->id));
        $orders = $this->asCompany($this->company, fn () => PO::query()->where('demand_type', 'CUSTOMER_ORDER')->where('demand_id', $line->id)->get());
        $this->assertCount(1, $orders);
        $this->assertEquals(7.0, (float) $orders->first()->planned_qty);
        $this->assertSame(PO::DRAFT, $orders->first()->status);
        $this->assertSame((string) $soBefore['number'], $orders->first()->demand_reference);

        // The sales order is Finance's: not a column changed, and no invoice raised.
        $this->assertEquals($soBefore, (array) DB::table('fin_sales_orders')->where('id', $line->order_id)->first());
        $this->assertSame($invoices, DB::table('fin_invoices')->count());

        // Output for the customer is held for the customer as it is received.
        $order = $orders->first();
        $this->asCompany($this->company, function () use ($order) {
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->sales);
            $life->approve($order->fresh(), $this->sales);
            $life->release($order->fresh(), $this->sales);
            app(ProductionExecution::class)->receiveOutput($order->fresh(), 7, $this->sales);
        });
        $this->assertEquals(10.0, $this->held($line->id));
        $row = $this->asCompany($this->company, fn () => app(CustomerOrders::class)->progress()->first());
        $this->assertSame('READY', $row['flag']);
        $this->assertEquals(7.0, $row['produced']);

        // Finance delivers 4: the hold shrinks to what is still owed, and the delivery note shows on the commitment.
        $this->asCompany($this->company, fn () => app(SalesOrderService::class)->deliver(FinSalesOrder::query()->findOrFail($line->order_id), [$line->id => 4]));
        $this->assertEquals(6.0, $this->held($line->id));
        $row = $this->asCompany($this->company, fn () => app(CustomerOrders::class)->progress()->first());
        $this->assertCount(1, $row['references']['deliveries']);
        $this->assertEquals(4.0, $row['references']['deliveries'][0]['qty']);

        // The customer cancels: every unit held for them is free again.
        $this->asCompany($this->company, fn () => FinSalesOrder::query()->findOrFail($line->order_id)->update(['status' => 'cancelled']));
        $this->assertEquals(0.0, $this->held($line->id));
        $this->assertSame('CANCELLED', $this->asCompany($this->company, fn () => CustomerCommitment::query()->first()->status));
    }

    public function test_made_to_stock_waits_for_mrp_unless_asked_and_configured_to_order_needs_a_configuration(): void
    {
        $table = $this->make('TABLE', 'MTS');
        $line = $this->salesLine('TABLE', 5);
        $this->commit($line)->assertSessionHas('success');
        $this->assertSame(0, $this->asCompany($this->company, fn () => PO::query()->where('demand_id', $line->id)->count()));
        $row = $this->asCompany($this->company, fn () => app(CustomerOrders::class)->progress()->first());
        $this->assertStringContainsString('neither in stock for this customer nor in production', implode(' ', $row['risks']));

        $this->commit($line, ['make' => true])->assertSessionHas('success');
        $this->assertSame(1, $this->asCompany($this->company, fn () => PO::query()->where('demand_id', $line->id)->count()));

        $this->make('DESK', 'CTO');
        $blank = $this->salesLine('DESK', 1, '');
        $this->commit($blank)->assertSessionHas('error');
        $this->commit($blank, ['configuration' => 'Oak top, 1.8 m, cable tray'])->assertSessionHas('success');
        $desk = $this->asCompany($this->company, fn () => PO::query()->where('demand_id', $blank->id)->firstOrFail());
        $this->assertStringContainsString('Oak top, 1.8 m', $desk->notes);
    }

    public function test_the_lead_time_waits_for_short_material_and_the_quote_says_so(): void
    {
        $chair = $this->make('CHAIR', 'MTO');
        $this->asCompany($this->company, fn () => PlanningParam::create(['company_id' => $this->company->id, 'stock_item_id' => $this->wood->id,
            'make_or_buy' => 'BUY', 'lead_time_days' => 6]));
        $line = $this->salesLine('CHAIR', 4);

        $quote = $this->actingAs($this->sales)->getJson(route('manufacturing.fulfilment.customer-orders.quote', ['line_id' => $line->id, 'factory_id' => $this->plant->id]))
            ->assertOk()->json();
        $this->assertEquals(4.0, $quote['to_make']);
        $this->assertSame(6, $quote['material_days']);
        $this->assertGreaterThanOrEqual(6, $quote['days']);
        $this->assertSame('WOOD', $quote['shortages'][0]['item']);
        $this->assertEquals(8.0, $quote['shortages'][0]['short']);
    }

    public function test_the_screens_and_who_may_use_them(): void
    {
        $this->make('CHAIR', 'MTO');
        $line = $this->salesLine('CHAIR', 2);
        $page = $this->actingAs($this->sales)->get(route('manufacturing.fulfilment.customer-orders'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Fulfilment/CustomerOrders', $page['component']);
        $this->assertSame($line->id, $page['props']['eligible'][0]['line_id']);
        $this->assertTrue($page['props']['can']['commit']);

        $this->actingAs($this->outsider)->get(route('manufacturing.fulfilment.customer-orders'))->assertForbidden();
        $this->actingAs($this->planner)->post(route('manufacturing.fulfilment.customer-orders.commit'), ['line_id' => $line->id])->assertForbidden();

        $this->commit($line)->assertSessionHas('success');
        $order = $this->asCompany($this->company, fn () => PO::query()->where('demand_id', $line->id)->firstOrFail());
        $show = $this->actingAs($this->sales)->get(route('manufacturing.production.orders.show', $order->uuid))->assertOk()->viewData('page');
        $this->assertSame('PO-ACME-9', $show['props']['customer']['customer_ref']);

        $cards = array_column($this->actingAs($this->sales)->get('/manufacturing/fulfilment')->assertOk()->viewData('page')['props']['cards'], 'path');
        $this->assertEqualsCanonicalizing(['/manufacturing/fulfilment/customer-orders', '/manufacturing/fulfilment/subcontracting'], $cards);
    }
}
