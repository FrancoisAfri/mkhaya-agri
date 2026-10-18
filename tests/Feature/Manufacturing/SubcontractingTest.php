<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\SubcontractOrder as SCO;
use App\Modules\Operations\Manufacturing\Services\CostAnalysis;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MFG-017, subcontracting: an operation sent out and inspected on return before the routing moves
 * on; a whole order made by a supplier from material we send; the supplier's charge on the order
 * and in procurement as a draft request -- never an invoice.
 */
class SubcontractingTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $bracket;

    private int $plater;

    private $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $role = Role::firstOrCreate(['name' => 'mfg test subcontract buyer', 'guard_name' => 'web']);
        $role->syncPermissions(RoleCatalog::all());
        $this->buyer = $this->makeUser('subcon@example.test', $role);
        $this->grantModuleTier($this->buyer);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)
            ->update(['require_material_for_approval' => false, 'require_qc_before_putaway' => false]));
        $this->plater = (int) DB::table('suppliers')->insertGetId(['company_name' => 'Bright Platers', 'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->bracket = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('BRACKET', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->bracket, [[$this->steel, 2, 0, 'kg']]);
        $this->routing($this->bracket, [
            ['Cut', 0, 5],
            ['Plate', 0, 0, ['is_subcontracted' => true, 'subcontractor_supplier_id' => $this->plater, 'subcontract_lead_days' => 4,
                'subcontract_cost_per_unit' => 12, 'machine_type' => null]],
            ['Assemble', 0, 5],
        ], [], true);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->steel->fresh(), 100, 10, $this->store, ['reference' => 'GRN']));
    }

    private function released(float $qty = 10): PO
    {
        return $this->asCompany($this->company, function () use ($qty) {
            $o = app(ProductionOrders::class)->create(['item' => $this->bracket, 'quantity' => $qty, 'due_date' => now()->addDays(10)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->buyer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->buyer);
            $life->approve($o->fresh(), $this->buyer);
            $life->release($o->fresh(), $this->buyer);

            return $o->fresh();
        });
    }

    private function op(PO $order, int $sequence): Op
    {
        return $this->asCompany($this->company, fn () => $order->operations()->where('sequence', $sequence)->firstOrFail());
    }

    public function test_an_operation_goes_out_comes_back_and_is_inspected_before_the_next_step(): void
    {
        $order = $this->released();
        $this->asCompany($this->company, fn () => app(ProductionExecution::class)->completeOperation($order, $this->op($order, 10), ['completed' => 10], $this->buyer));

        // In-house operations cannot be sent out.
        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.store'), [
            'production_order' => $order->uuid, 'operation' => $this->op($order, 30)->uuid,
        ])->assertSessionHas('error');

        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.store'), [
            'production_order' => $order->uuid, 'operation' => $this->op($order, 20)->uuid,
        ])->assertRedirect();
        $sco = $this->asCompany($this->company, fn () => SCO::query()->firstOrFail());
        $this->assertSame($this->plater, (int) $sco->supplier_id, 'The routing names the subcontractor.');
        $this->assertEquals(12.0, (float) $sco->unit_price);
        $this->assertSame(now()->addDays(4)->toDateString(), $sco->due_date->toDateString());

        $invoices = DB::table('fin_invoices')->count();
        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.send', $sco->uuid), ['dispatch_reference' => 'DN-OUT-1'])->assertSessionHas('success');
        $sco->refresh();
        $this->assertSame(SCO::SENT, $sco->status);
        $this->assertSame(Op::IN_PROGRESS, $this->op($order, 20)->status);
        $request = DB::table('purchase_request_items')->where('external_ref', $sco->number)->first();
        $this->assertNotNull($request, 'The supplier charge reaches procurement.');
        $this->assertSame('service', $request->item_type);
        $this->assertEquals(120.0, (float) $request->line_total);
        $this->assertSame('draft', DB::table('purchase_requests')->where('id', $sco->purchase_request_id)->value('status'));

        // Nine come back; one was lost at the plater.
        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.receive', $sco->uuid), ['qty' => 9, 'supplier_reference' => 'BP-551'])->assertSessionHas('success');
        $plate = $this->op($order, 20);
        $this->assertSame(Op::COMPLETED, $plate->status);
        $this->assertEquals(9.0, (float) $plate->qty_completed);
        $this->assertEquals(1.0, (float) $plate->qty_scrapped);
        $inspection = $this->asCompany($this->company, fn () => Inspection::query()->where('order_operation_id', $plate->id)->firstOrFail());
        $this->assertSame($this->plater, (int) $inspection->supplier_id);
        $this->assertNotSame(Op::READY, $this->op($order, 30)->status, 'Assembly waits for the inspection.');

        $this->asCompany($this->company, fn () => app(QualityControl::class)->record($inspection, [['name' => 'Plating thickness', 'passed' => true]], $this->buyer));
        $this->assertSame('PASSED', $this->op($order, 20)->gate_status);
        $this->assertSame(Op::READY, $this->op($order, 30)->status);

        // The cost is what came back at the agreed price -- on the order, not on an invoice.
        $order->refresh();
        $this->assertEquals(108.0, (float) $order->act_subcontract_cost);
        $this->assertEquals(108.0, $this->asCompany($this->company, fn () => app(CostAnalysis::class)->actuals($order)['subcontract']));
        $this->assertSame($invoices, DB::table('fin_invoices')->count());
    }

    public function test_a_whole_order_is_made_from_material_we_send_and_received_into_quarantine(): void
    {
        $order = $this->released(10);
        $sco = $this->asCompany($this->company, fn () => app(\App\Modules\Operations\Manufacturing\Services\Subcontracting::class)
            ->create($order, null, ['supplier_id' => $this->plater, 'unit_price' => 30], $this->buyer));
        $material = $sco->materials()->firstOrFail();
        $this->assertEquals(20.0, (float) $material->qty_planned, 'Everything the order still needs goes with it.');

        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.send', $sco->uuid))->assertSessionHas('success');
        $this->assertEquals(20.0, (float) DB::table('mfg_production_order_components')->where('order_id', $order->id)->value('issued_qty'));
        $this->assertEquals(80.0, (float) $this->steel->fresh()->qty_on_hand);

        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.receive', $sco->uuid), ['qty' => 6, 'lot_number' => 'BP-L1'])->assertSessionHas('success');
        $sco->refresh();
        $this->assertSame(SCO::PART_RECEIVED, $sco->status);
        $quarantined = (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $this->bracket->stock_item_id)->where('status', 'QUARANTINE')->sum('qty');
        $this->assertEquals(6.0, $quarantined, 'Subcontracted output is quarantined even where the factory does not quarantine its own.');
        $final = $this->asCompany($this->company, fn () => Inspection::query()->where('production_order_id', $order->id)->where('stage', 'FINAL')->firstOrFail());
        $this->assertSame($this->plater, (int) $final->supplier_id);

        // Unused steel comes back; a short delivery is closed only with a reason.
        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.return', ['uuid' => $sco->uuid, 'material' => $material->id]), ['qty' => 5])->assertSessionHas('success');
        $this->assertEquals(85.0, (float) $this->steel->fresh()->qty_on_hand);
        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.close', $sco->uuid))->assertSessionHas('error');
        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.close', $sco->uuid), ['reason' => 'Supplier scrapped four'])->assertSessionHas('success');
        $this->assertSame(SCO::CLOSED, $sco->fresh()->status);
        $this->assertEquals(180.0, (float) $order->fresh()->act_subcontract_cost);
    }

    public function test_the_screens_and_who_may_use_them(): void
    {
        $order = $this->released();
        $index = $this->actingAs($this->buyer)->get(route('manufacturing.fulfilment.subcontracts.index'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Fulfilment/Subcontracts', $index['component']);
        $candidate = collect($index['props']['candidates'])->firstWhere('id', $order->uuid);
        $this->assertSame(['20 Plate'], array_column($candidate['operations'], 'name'));

        $this->actingAs($this->outsider)->get(route('manufacturing.fulfilment.subcontracts.index'))->assertForbidden();
        $this->actingAs($this->planner)->post(route('manufacturing.fulfilment.subcontracts.store'), ['production_order' => $order->uuid])->assertForbidden();

        $sco = $this->asCompany($this->company, fn () => app(\App\Modules\Operations\Manufacturing\Services\Subcontracting::class)
            ->create($order, $this->op($order, 20), [], $this->buyer));
        $show = $this->actingAs($this->buyer)->get(route('manufacturing.fulfilment.subcontracts.show', $sco->uuid))->assertOk()->viewData('page');
        $this->assertSame('Bright Platers', $show['props']['subcontract']['supplier']);
        $this->actingAs($this->buyer)->post(route('manufacturing.fulfilment.subcontracts.cancel', $sco->uuid), ['reason' => 'Doing it in-house'])->assertSessionHas('success');
        $this->assertSame(SCO::CANCELLED, $sco->fresh()->status);

        $orderPage = $this->actingAs($this->buyer)->get(route('manufacturing.production.orders.show', $order->uuid))->assertOk()->viewData('page');
        $this->assertSame($sco->number, $orderPage['props']['subcontracts'][0]['number']);
    }
}
