<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\OrderVariance;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\CostAnalysis;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\Profitability;
use App\Modules\Operations\Manufacturing\Services\StandardCosting;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MFG-011 -- standard cost, variances and profitability, on real orders and real invoices.
 *
 * A WIDGET is 2 kg STEEL (standard price 10) and 4 BOLT (2), so material is 28 a unit. One
 * operation, Assemble, takes 6 minutes a unit at a centre charging 120/h for the machine and 60/h
 * for labour: 12 machine and 6 labour a unit. Overhead is 50% of labour and machine: 9. Standard
 * unit cost 28 + 12 + 6 + 9 = 55.
 */
class CostingTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private FinStockItem $steel;

    private FinStockItem $bolt;

    private $costAccountant;

    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $acc = Role::firstOrCreate(['name' => 'mfg test cost accountant', 'guard_name' => 'web']);
        $acc->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_COSTS, RoleCatalog::MANAGE_COSTS, RoleCatalog::VIEW_ORDERS]);
        $ctl = Role::firstOrCreate(['name' => 'mfg test financial controller', 'guard_name' => 'web']);
        $ctl->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_COSTS, RoleCatalog::APPROVE_COSTS]);
        $this->costAccountant = $this->makeUser('mfg-cost@example.test', $acc);
        $this->controller = $this->makeUser('mfg-fc@example.test', $ctl);
        $this->grantModuleTier($this->costAccountant);
        $this->grantModuleTier($this->controller);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, function () {
            Shift::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
                'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true]);
            FactoryPolicy::for(null, $this->company->id)->update(['overhead_percent' => 50, 'require_qc_before_putaway' => false, 'allow_over_issue' => true]);
        });

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->bolt = $this->stockItem('BOLT', 'ea', 2);
        $this->widget = $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $this->stockItem('WIDGET', 'ea', 0)->id, 'kind' => 'FINISHED',
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true,
        ]));
        $this->approvedBom($this->widget, [[$this->steel, 2, 0, 'kg'], [$this->bolt, 4, 0, 'ea']]);
        $centre = $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'ASM', 'name' => 'Assembly',
            'cost_per_hour' => 120, 'labour_rate_per_hour' => 60, 'is_active' => true,
        ]));
        $this->routing($this->widget, [['Assemble', 0, 6, ['work_centre_id' => $centre->id]]], [], true);
    }

    private function standard(float $lot = 10): StandardCost
    {
        return $this->asCompany($this->company, fn () => app(StandardCosting::class)->calculate($this->widget, $lot, $this->costAccountant));
    }

    private function approved(): StandardCost
    {
        $sc = $this->standard();

        return $this->asCompany($this->company, fn () => app(StandardCosting::class)->approve($sc, $this->controller));
    }

    public function test_a_standard_cost_rolls_up_material_labour_machine_and_overhead(): void
    {
        $sc = $this->standard(10);

        $this->assertSame(StandardCost::DRAFT, $sc->status);
        $this->assertEquals(280.0, (float) $sc->material_cost);
        $this->assertEquals(120.0, (float) $sc->machine_cost);   // 60 min x 120/h
        $this->assertEquals(60.0, (float) $sc->labour_cost);     // 60 min x 60/h
        $this->assertEquals(90.0, (float) $sc->overhead_cost);   // 50% of 180
        $this->assertEquals(55.0, (float) $sc->unit_cost);
        $this->assertNull($sc->warnings);

        $bases = $sc->lines()->pluck('basis', 'reference')->all();
        $this->assertSame('standard price', $bases['STEEL']);
        $this->assertSame('50.0000% of labour and machine', $sc->lines()->where('category', 'OVERHEAD')->value('basis'));
    }

    public function test_approval_supersedes_the_last_standard_updates_the_stock_price_and_locks_the_record(): void
    {
        $first = $this->approved();
        $this->assertEquals(55.0, (float) FinStockItem::withoutGlobalScopes()->find($this->widget->stock_item_id)->standard_cost);

        // Steel goes up; a new version is calculated and approved.
        FinStockItem::withoutGlobalScopes()->whereKey($this->steel->id)->update(['standard_cost' => 12]);
        $second = $this->approved();

        $this->assertSame(StandardCost::SUPERSEDED, $first->fresh()->status);
        $this->assertSame(2, $second->version);
        $this->assertEquals(59.0, (float) $second->unit_cost);

        try {
            DB::transaction(fn () => DB::table('mfg_standard_costs')->where('id', $second->id)->update(['unit_cost' => 1]));
            $this->fail('An approved standard cost was edited.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('calculate a new version', $e->getMessage());
        }

        // A new order is measured against the standard in force when it was raised.
        $order = $this->asCompany($this->company, fn () => app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => 10,
            'due_date' => now()->addDay()->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer));
        $this->assertSame($second->id, (int) $order->standard_cost_id);
    }

    public function test_a_material_with_no_price_cannot_be_approved_into_a_standard(): void
    {
        FinStockItem::withoutGlobalScopes()->whereKey($this->bolt->id)->update(['standard_cost' => 0, 'avg_cost' => 0]);
        $sc = $this->standard();

        $this->assertContains('No price for material BOLT', $sc->warnings);
        $this->expectException(\DomainException::class);
        $this->asCompany($this->company, fn () => app(StandardCosting::class)->approve($sc, $this->controller));
    }

    private function wastefulOrder(): PO
    {
        $this->approved();

        return $this->asCompany($this->company, function () {
            // Steel was bought at 11, not the standard 10.
            app(StockLedger::class)->receive($this->steel->fresh(), 100, 11, $this->store, ['reference' => 'GRN']);
            app(StockLedger::class)->receive($this->bolt->fresh(), 100, 2, $this->store, ['reference' => 'GRN']);

            $order = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => 10,
                'due_date' => now()->addDay()->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->engineer);
            $life->approve($order, $this->approver);
            $life->release($order, $this->approver);

            $floor = app(ProductionExecution::class);
            $components = $order->components()->get()->keyBy('stock_item_id');
            // 22 kg of steel instead of 20; bolts as planned.
            $floor->issue($order->fresh(), $components[$this->steel->id], 22, $this->engineer);
            $floor->issue($order->fresh(), $components[$this->bolt->id], 40, $this->engineer);
            // 75 minutes on the machine and 90 of labour, against 60 standard for 10.
            $floor->completeOperation($order->fresh(), $order->operations()->first(), ['completed' => 10, 'machine_minutes' => 75, 'labour_minutes' => 90], $this->engineer);
            $floor->receiveOutput($order->fresh(), 10, $this->engineer, ['lot_number' => 'W1']);

            return $order->fresh();
        });
    }

    public function test_variances_explain_the_difference_between_actual_and_standard_and_add_up(): void
    {
        $order = $this->wastefulOrder();
        $a = $this->asCompany($this->company, fn () => app(CostAnalysis::class)->analyse($order));
        $v = collect($a['variances'])->keyBy('kind');

        $this->assertEquals(22.0, $v['MATERIAL_PRICE']['amount']);      // (11 - 10) x 22 kg
        $this->assertEquals(20.0, $v['MATERIAL_USAGE']['amount']);      // (22 - 20) kg x 10
        $this->assertEquals(0.0, $v['LABOUR_RATE']['amount']);
        $this->assertSame('PARTIAL', $v['LABOUR_RATE']['data_quality'], 'The labour rate variance claimed data it does not have.');
        $this->assertEquals(30.0, $v['LABOUR_EFFICIENCY']['amount']);   // (1.5 - 1.0) h x 60
        $this->assertEquals(30.0, $v['MACHINE']['amount']);             // (1.25 - 1.0) h x 120
        $this->assertEquals(30.0, $v['OVERHEAD']['amount']);            // 50% of 60 extra
        $this->assertEquals(132.0, $v['TOTAL']['amount']);
        $this->assertEquals(0.0, $v['TOTAL']['inputs']['unexplained'], 'The variances do not add up to the total.');

        $steel = collect($v['MATERIAL_USAGE']['inputs']['lines'])->firstWhere('item', 'STEEL');
        $this->assertEquals(20.0, $steel['standard_qty']);
        $this->assertNotEmpty($v['MATERIAL_PRICE']['inputs']['movements'], 'A variance did not name its source records.');

        $this->assertSame(CostAnalysis::PROVISIONAL, $a['status']);
        $this->assertContains('The order is not closed.', $a['outstanding']);
    }

    public function test_closing_freezes_the_variances_and_makes_the_cost_final(): void
    {
        $order = $this->wastefulOrder();

        $this->asCompany($this->company, function () use ($order) {
            $life = app(ProductionOrderLifecycle::class);
            $life->sendToQuality($order->fresh(), $this->approver);
            $life->complete($order->fresh(), $this->approver);
            $life->close($order->fresh(), $this->approver);
        });

        $stored = OrderVariance::withoutGlobalScopes()->where('order_id', $order->id)->pluck('amount', 'kind')->map(fn ($a) => (float) $a)->all();
        $this->assertEquals(132.0, $stored['TOTAL']);
        $this->assertEquals(22.0, $stored['MATERIAL_PRICE']);

        $a = $this->asCompany($this->company, fn () => app(CostAnalysis::class)->analyse($order->fresh()));
        $this->assertSame(CostAnalysis::FINAL, $a['status'], 'Outstanding: '.implode(' ', $a['outstanding']));

        // A draft that has done nothing is an estimate.
        $draft = $this->asCompany($this->company, fn () => app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => 5,
            'due_date' => now()->addDay()->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer));
        $this->assertSame(CostAnalysis::ESTIMATED, $this->asCompany($this->company, fn () => app(CostAnalysis::class)->analyse($draft))['status']);
    }

    private function invoice(string $currency, float $qty, float $total, ?int $partyId = null): void
    {
        $entity = FinStockItem::withoutGlobalScopes()->find($this->widget->stock_item_id)->entity_id;
        $partyId ??= (int) DB::table('fin_parties')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity,
            'kind' => 'customer', 'code' => 'C'.Str::random(5), 'name' => 'Acme Retail', 'created_at' => now(), 'updated_at' => now()]);
        $finItem = DB::table('fin_items')->where('code', 'WIDGET')->value('id') ?? DB::table('fin_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity, 'code' => 'WIDGET', 'name' => 'Widget',
            'created_at' => now(), 'updated_at' => now()]);
        $invoice = DB::table('fin_invoices')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'entity_id' => $entity,
            'party_id' => $partyId, 'direction' => 'receivable', 'doc_type' => 'invoice', 'number' => 'INV-'.Str::random(5),
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'currency' => $currency,
            'status' => 'open', 'total' => $total, 'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('fin_accounts')->where('code', '4000')->value('id') ?? DB::table('fin_accounts')->value('id');
        DB::table('fin_invoice_lines')->insert(['company_id' => $this->company->id, 'entity_id' => $entity, 'invoice_id' => $invoice,
            'account_id' => $account, 'description' => 'Widgets', 'item_id' => $finItem, 'quantity' => $qty, 'unit_price' => $total / $qty,
            'line_total' => $total, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_profitability_uses_actual_cost_says_how_final_it_is_and_never_converts_currencies(): void
    {
        $order = $this->wastefulOrder();   // 10 made for 682: 68.20 a unit
        $this->invoice('ZAR', 8, 800);
        $this->invoice('USD', 2, 50);

        $report = $this->asCompany($this->company, fn () => app(Profitability::class)->report('product', now()->startOfMonth()->toDateString(), now()->toDateString()));
        $row = $report['rows'][0];

        $this->assertEquals(800.0, $row['revenue']);
        $this->assertEquals(8.0, $row['qty']);
        $this->assertEquals(68.2, $row['unit_cost']);
        $this->assertEquals(545.6, $row['cost']);
        $this->assertEquals(254.4, $row['margin']);
        $this->assertSame(CostAnalysis::PROVISIONAL, $row['cost_status'], 'A margin was called final while the order is still open.');
        $this->assertSame('USD', $report['excluded'][0]['currency']);

        // By customer and by order use the same figures.
        $customer = $this->asCompany($this->company, fn () => app(Profitability::class)->report('customer', now()->startOfMonth()->toDateString(), now()->toDateString()));
        $this->assertSame('Acme Retail', $customer['rows'][0]['label']);
        $byOrder = $this->asCompany($this->company, fn () => app(Profitability::class)->report('order', now()->startOfMonth()->toDateString(), now()->toDateString()));
        $this->assertSame($order->number, $byOrder['rows'][0]['label']);
        $this->assertEquals(1000.0, $byOrder['rows'][0]['revenue']); // 10 made at the period's 100 average selling price
    }

    public function test_the_costing_screens_and_who_may_do_what(): void
    {
        // The costing cards now sit in the reports hub; only theirs are compared.
        $cards = array_values(array_filter(array_column($this->actingAs($this->costAccountant)->get('/manufacturing/quality')->assertOk()->viewData('page')['props']['cards'], 'path'), fn ($p) => str_starts_with($p, '/manufacturing/costing/')));
        $this->assertEqualsCanonicalizing(['/manufacturing/costing/standards', '/manufacturing/costing/variances', '/manufacturing/costing/profitability'], $cards);
        $this->actingAs($this->engineer)->get(route('manufacturing.costing.standards.index'))->assertForbidden();

        $this->actingAs($this->costAccountant)->post(route('manufacturing.costing.standards.calculate'), ['item_uuid' => $this->widget->uuid, 'costing_qty' => 10])
            ->assertSessionHasNoErrors()->assertRedirect();
        $sc = $this->asCompany($this->company, fn () => StandardCost::sole());

        $page = $this->actingAs($this->costAccountant)->get(route('manufacturing.costing.standards.show', $sc->uuid))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Costing/StandardShow', $page['component']);
        $this->assertFalse($page['props']['can']['approve'], 'The person who calculated the standard may also approve it.');
        $this->actingAs($this->costAccountant)->post(route('manufacturing.costing.standards.approve', $sc->uuid))->assertForbidden();
        $this->actingAs($this->controller)->post(route('manufacturing.costing.standards.approve', $sc->uuid))->assertSessionHas('success');

        $this->asCompany($this->company, fn () => $sc->fresh());
        $order = $this->wastefulOrderWithoutNewStandard();
        $variances = $this->actingAs($this->costAccountant)->get(route('manufacturing.costing.variances'))->assertOk()->viewData('page');
        $this->assertSame($order->number, $variances['props']['rows'][0]['number']);

        $profit = $this->actingAs($this->costAccountant)->get(route('manufacturing.costing.profitability', ['dimension' => 'factory']))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Costing/Profitability', $profit['component']);

        // The order page shows standard against actual only to those who may see costs.
        Role::findByName('mfg test planner', 'web')->givePermissionTo(RoleCatalog::VIEW_ORDERS);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertNotNull($this->actingAs($this->costAccountant)->get(route('manufacturing.production.orders.show', $order->uuid))->viewData('page')['props']['cost_analysis']);
        $this->assertNull($this->actingAs($this->planner->fresh())->get(route('manufacturing.production.orders.show', $order->uuid))->viewData('page')['props']['cost_analysis'] ?? null);
    }

    private function wastefulOrderWithoutNewStandard(): PO
    {
        return $this->asCompany($this->company, function () {
            app(StockLedger::class)->receive($this->steel->fresh(), 50, 10, $this->store, ['reference' => 'GRN']);
            app(StockLedger::class)->receive($this->bolt->fresh(), 50, 2, $this->store, ['reference' => 'GRN']);
            $order = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => 5,
                'due_date' => now()->addDay()->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->engineer);
            $life->approve($order, $this->approver);
            $life->release($order, $this->approver);
            $floor = app(ProductionExecution::class);
            foreach ($order->components()->get() as $c) {
                $floor->issue($order->fresh(), $c, (float) $c->required_qty, $this->engineer);
            }
            $floor->completeOperation($order->fresh(), $order->operations()->first(), ['completed' => 5, 'machine_minutes' => 30, 'labour_minutes' => 30], $this->engineer);
            $floor->receiveOutput($order->fresh(), 5, $this->engineer, ['lot_number' => 'W5']);

            return $order->fresh();
        });
    }
}
