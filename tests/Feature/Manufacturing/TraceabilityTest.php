<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Finance\Accounting\Support\StockContext;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\Recalls;
use App\Modules\Operations\Manufacturing\Services\Traceability;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * MFG-010 acceptance: traceability is accurate across partial batches, split lots, merged lots,
 * rework and material returns -- each built on real stock movements -- and a recall holds,
 * accounts for and closes on exactly the lots it reaches.
 *
 * A WIDGET is 2 kg of STEEL (lot-tracked) and 1 BOLT (issued without a lot). A KIT is made from one
 * WIDGET -- which is how rework is modelled here: finished lots consumed by a later order.
 */
class TraceabilityTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private Item $kit;

    private FinStockItem $steel;

    private FinStockItem $bolt;

    private int $supplierA;

    private int $supplierB;

    private $tracer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $role = Role::firstOrCreate(['name' => 'mfg test quality tracer', 'guard_name' => 'web']);
        $role->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_QUALITY, RoleCatalog::VIEW_TRACE, RoleCatalog::MANAGE_RECALL]);
        $this->tracer = $this->makeUser('mfg-tracer@example.test', $role);
        $this->grantModuleTier($this->tracer);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, function () {
            Shift::create([
                'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
                'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true,
            ]);
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_qc_before_putaway' => false, 'require_material_for_approval' => false, 'require_routing' => false]);
        });

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->bolt = $this->stockItem('BOLT', 'ea', 1);
        $finished = fn (string $code) => $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $this->stockItem($code, 'ea', 0)->id, 'kind' => 'FINISHED',
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true, 'is_lot_tracked' => true,
        ]));
        $this->widget = $finished('WIDGET');
        $this->kit = $finished('KIT');
        $this->approvedBom($this->widget, [[$this->steel, 2, 0, 'kg'], [$this->bolt, 1, 0, 'ea']]);
        $this->approvedBom($this->kit, [[FinStockItem::withoutGlobalScopes()->find($this->widget->stock_item_id), 1, 0, 'ea']]);

        $supplier = fn (string $name) => (int) DB::table('suppliers')->insertGetId(['company_name' => $name, 'company_id' => $this->company->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->supplierA = $supplier('Mill A');
        $this->supplierB = $supplier('Mill B');

        // Bolts are not lot-tracked.
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->bolt->fresh(), 500, 1, $this->store, ['reference' => 'GRN-B']));
    }

    private function ledger(): StockLedger { return app(StockLedger::class); }

    private function receiveLot(string $number, float $qty, int $supplierId): FinStockLot
    {
        return $this->asCompany($this->company, function () use ($number, $qty, $supplierId) {
            $lot = $this->ledger()->lot($this->steel->fresh(), $number, ['origin' => 'RECEIPT', 'supplier_id' => $supplierId]);
            $this->ledger()->receive($this->steel->fresh(), $qty, 10, $this->store, ['lot_id' => $lot->id, 'reference' => "GRN {$number}"]);

            return $lot;
        });
    }

    /**
     * Run an order: issue material by lot (and bolts without one), return any listed, receive the
     * output as a lot.
     *
     * @param  array<int, float>  $issues  steel or widget lot id => qty
     * @param  array<int, float>  $returns lot id => qty returned
     */
    private function make(Item $product, float $qty, array $issues, string $outputLot, array $returns = []): PO
    {
        return $this->asCompany($this->company, function () use ($product, $qty, $issues, $outputLot, $returns) {
            $order = app(ProductionOrders::class)->create(['item' => $product, 'quantity' => $qty,
                'due_date' => now()->addDays(5)->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->engineer);
            $life->approve($order, $this->approver);
            $life->release($order, $this->approver);
            $floor = app(ProductionExecution::class);
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['allow_over_issue' => true]);

            foreach ($issues as $lotId => $q) {
                $lot = FinStockLot::withoutGlobalScopes()->find($lotId);
                $component = $order->components()->where('stock_item_id', $lot->item_id)->firstOrFail();
                $floor->issue($order->fresh(), $component->fresh(), $q, $this->engineer, ['lot_id' => $lotId]);
            }
            foreach ($returns as $lotId => $q) {
                $lot = FinStockLot::withoutGlobalScopes()->find($lotId);
                $component = $order->components()->where('stock_item_id', $lot->item_id)->firstOrFail();
                $floor->returnMaterial($order->fresh(), $component->fresh(), $q, $this->engineer, ['lot_id' => $lotId]);
            }
            if ($bolt = $order->components()->where('stock_item_id', $this->bolt->id)->first()) {
                $floor->issue($order->fresh(), $bolt, (float) $bolt->required_qty, $this->engineer);
            }
            $floor->receiveOutput($order->fresh(), $qty, $this->engineer, ['lot_number' => $outputLot]);

            return $order->fresh();
        });
    }

    private function lotOf(Item $product, string $number): FinStockLot
    {
        return FinStockLot::withoutGlobalScopes()->where('item_id', $product->stock_item_id)->where('lot_number', $number)->sole();
    }

    private function lotNumbers(array $trace): array
    {
        $numbers = array_column($trace['nodes'], 'lot');
        sort($numbers);

        return $numbers;
    }

    private function trace(): Traceability { return app(Traceability::class); }

    public function test_a_partial_batch_traces_to_every_order_that_used_part_of_it_and_only_those(): void
    {
        $h1 = $this->receiveLot('H1', 100, $this->supplierA);
        $h2 = $this->receiveLot('H2', 100, $this->supplierB);

        $this->make($this->widget, 5, [$h1->id => 10], 'W1');
        $this->make($this->widget, 10, [$h1->id => 12, $h2->id => 8], 'W2');
        $this->make($this->widget, 5, [$h2->id => 10], 'W3');

        $this->assertSame(['H1', 'W1', 'W2'], $this->lotNumbers($this->trace()->forward($h1)));

        // W2 came from both mills; the edges carry the quantity of each that went in.
        $back = $this->trace()->backward($this->lotOf($this->widget, 'W2'));
        $this->assertSame(['H1', 'H2', 'W2'], $this->lotNumbers($back));
        $qty = collect($back['edges'])->mapWithKeys(fn ($e) => [$back['nodes'][$e['from']]['lot'] => $e['qty']])->all();
        $this->assertEquals(['H1' => 12.0, 'H2' => 8.0], $qty);

        // The bolts went in without a lot: reported, never silently dropped.
        $this->assertSame('BOLT', $back['untracked'][0]['item']);

        // The supplier and receipt behind a lot.
        $detail = $this->asCompany($this->company, fn () => $this->trace()->lotDetail($h1));
        $this->assertSame('Mill A', $detail['supplier']);
        $this->assertEquals(100.0, $detail['receipts'][0]['qty']);
        $this->assertEquals(78.0, array_sum(array_column($detail['positions'], 'qty')));
    }

    public function test_a_split_lot_carries_its_parents_history_forward(): void
    {
        $h1 = $this->receiveLot('H1', 100, $this->supplierA);
        $child = $this->asCompany($this->company, fn () => $this->ledger()->splitLot($this->steel->fresh(), $this->store, $h1, 40, 'H1-B'));

        $this->assertSame('SPLIT', $child->origin);
        $this->assertSame($this->supplierA, (int) $child->supplier_id);
        $this->assertEquals(60.0, $this->trace()->inStock($h1->id));
        $this->assertEquals(40.0, $this->trace()->inStock($child->id));

        $this->make($this->widget, 5, [$child->id => 10], 'W9');

        $this->assertSame(['H1', 'H1-B', 'W9'], $this->lotNumbers($this->trace()->forward($h1)), 'Forward from the parent missed the split.');
        $this->assertSame(['H1', 'H1-B', 'W9'], $this->lotNumbers($this->trace()->backward($this->lotOf($this->widget, 'W9'))));
    }

    public function test_a_merged_lot_traces_back_to_every_lot_merged_into_it(): void
    {
        $h2 = $this->receiveLot('H2', 50, $this->supplierA);
        $h3 = $this->receiveLot('H3', 30, $this->supplierB);
        $merged = $this->asCompany($this->company, fn () => $this->ledger()->mergeLots($this->steel->fresh(), $this->store, [$h2->id => 20, $h3->id => 30], 'M1'));

        $this->assertEquals(50.0, $this->trace()->inStock($merged->id));
        $this->assertEquals(30.0, $this->trace()->inStock($h2->id));

        $this->make($this->widget, 10, [$merged->id => 20], 'W4');

        $this->assertSame(['H2', 'H3', 'M1', 'W4'], $this->lotNumbers($this->trace()->backward($this->lotOf($this->widget, 'W4'))));
        // Forward from either source reaches the product.
        $this->assertContains('W4', $this->lotNumbers($this->trace()->forward($h3)));
    }

    public function test_rework_carries_the_trace_through_a_second_order(): void
    {
        $h1 = $this->receiveLot('H1', 100, $this->supplierA);
        $this->make($this->widget, 5, [$h1->id => 10], 'W1');
        $w1 = $this->lotOf($this->widget, 'W1');

        // The widgets are reworked into kits by a later order.
        $this->make($this->kit, 3, [$w1->id => 3], 'K1');

        $this->assertSame(['H1', 'K1', 'W1'], $this->lotNumbers($this->trace()->forward($h1)));
        $this->assertSame(['H1', 'K1', 'W1'], $this->lotNumbers($this->trace()->backward($this->lotOf($this->kit, 'K1'))));
    }

    public function test_material_returned_in_full_is_not_an_input_and_a_partial_return_counts_net(): void
    {
        $h1 = $this->receiveLot('H1', 100, $this->supplierA);
        $h2 = $this->receiveLot('H2', 100, $this->supplierB);

        // H2 was issued by mistake and all of it came back; H1 partly came back.
        $this->make($this->widget, 5, [$h1->id => 14, $h2->id => 6], 'W5', [$h2->id => 6, $h1->id => 4]);

        $back = $this->trace()->backward($this->lotOf($this->widget, 'W5'));
        $this->assertSame(['H1', 'W5'], $this->lotNumbers($back), 'A fully returned lot was counted as an input.');
        $this->assertEquals(10.0, $back['edges'][0]['qty']);
        $this->assertNotContains('W5', $this->lotNumbers($this->trace()->forward($h2)));
    }

    public function test_a_recall_holds_what_is_here_accounts_for_what_has_gone_and_closes_only_when_decided(): void
    {
        $h1 = $this->receiveLot('H1', 100, $this->supplierA);
        $this->make($this->widget, 5, [$h1->id => 10], 'W1');
        $this->make($this->widget, 10, [$h1->id => 20], 'W2');
        $w2 = $this->lotOf($this->widget, 'W2');

        // Four widgets from W2 have already been dispatched to a customer.
        $this->asCompany($this->company, fn () => app(InventoryService::class)->issue(FinStockItem::withoutGlobalScopes()->find($this->widget->stock_item_id),
            4, null, 'DN-501 Acme', $this->store, null, new StockContext(lotId: $w2->id, strict: true)));

        $recalls = app(Recalls::class);
        $recall = $this->asCompany($this->company, fn () => $recalls->open($h1, 'Steel H1 out of spec', 'Mill reported a chemistry error on heat H1', 'CLASS_II', $this->tracer));
        $this->assertStringStartsWith('RCL-', $recall->number);

        $lots = $recall->lots()->with('lot')->get()->keyBy(fn ($rl) => $rl->lot->lot_number);
        $this->assertEqualsCanonicalizing(['H1', 'W1', 'W2'], $lots->keys()->all());
        $this->assertEquals(4.0, (float) $lots['W2']->dispatched_at_open);
        $this->assertEquals(6.0, (float) $lots['W2']->in_stock_at_open);

        $held = $this->asCompany($this->company, fn () => $recalls->holdAll($recall, $this->tracer));
        $this->assertEquals(70.0 + 5.0 + 6.0, $held);
        $this->assertEquals(0.0, $this->ledger()->available(FinStockItem::withoutGlobalScopes()->find($this->steel->id), $this->store->id, $h1->id));

        // Closing with stock still held is refused.
        try {
            $this->asCompany($this->company, fn () => $recalls->close($recall, 'Done', $this->tracer));
            $this->fail('A recall closed with stock still on hold.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('still on hold', $e->getMessage());
        }

        $this->asCompany($this->company, function () use ($recalls, $recall, $lots) {
            $recalls->dispose($recall, $lots['W2']->fresh(), 6, 'Scrapped', $this->tracer);
            $recalls->dispose($recall, $lots['W1']->fresh(), 5, 'Scrapped', $this->tracer);
            $recalls->release($recall, $lots['H1']->fresh(), 70, 'Retest passed', $this->tracer);
            $recalls->record($recall, 'CUSTOMER_NOTIFIED', $lots['W2'], null, 'Acme (DN-501)', 'Phoned and emailed', $this->tracer);
            $recalls->record($recall, 'RETRIEVED', $lots['W2']->fresh(), 4, 'Acme', null, $this->tracer);
            $recalls->close($recall->fresh(), 'All affected stock scrapped or cleared; customer stock retrieved.', $this->tracer);
        });

        $this->assertSame(Recall::CLOSED, $recall->fresh()->status);
        $this->assertEquals(4.0, (float) $lots['W2']->fresh()->retrieved);
        $this->assertEquals(70.0, $this->ledger()->available(FinStockItem::withoutGlobalScopes()->find($this->steel->id), $this->store->id, $h1->id));
        $this->assertEquals(0.0, (float) FinStockPosition::withoutGlobalScopes()->where('lot_id', $w2->id)->sum('qty'));
    }

    public function test_the_traceability_and_recall_screens(): void
    {
        $h1 = $this->receiveLot('H1', 100, $this->supplierA);
        $this->make($this->widget, 5, [$h1->id => 10], 'W1');

        $cards = array_column($this->actingAs($this->tracer)->get('/manufacturing/quality')->viewData('page')['props']['cards'], 'path');
        $this->assertContains('/manufacturing/quality/traceability', $cards);
        $this->assertContains('/manufacturing/quality/recalls', $cards);
        $this->actingAs($this->engineer)->get(route('manufacturing.quality.traceability'))->assertForbidden();

        $search = $this->actingAs($this->tracer)->get(route('manufacturing.quality.traceability', ['search' => 'H1']))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Quality/Traceability', $search['component']);
        $this->assertSame(['H1'], array_column($search['props']['matches'], 'lot'));

        $page = $this->actingAs($this->tracer)->get(route('manufacturing.quality.traceability', ['lot' => $h1->uuid]))->assertOk()->viewData('page');
        $this->assertSame(['H1', 'W1'], collect($page['props']['forward']['nodes'])->pluck('lot')->sort()->values()->all());
        $this->assertSame('Mill A', $page['props']['detail']['supplier']);
        $this->assertTrue($page['props']['can']['recall']);

        $this->actingAs($this->tracer)->post(route('manufacturing.quality.recalls.store'), [
            'lot' => $h1->uuid, 'title' => 'Heat H1', 'reason' => 'Out of spec', 'severity' => 'CLASS_I',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $recall = $this->asCompany($this->company, fn () => Recall::sole());

        $this->actingAs($this->tracer)->post(route('manufacturing.quality.recalls.hold', $recall->uuid))->assertSessionHas('success');
        $show = $this->actingAs($this->tracer)->get(route('manufacturing.quality.recalls.show', $recall->uuid))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Quality/RecallShow', $show['component']);
        $this->assertEquals(90.0 + 5.0, collect($show['props']['lots'])->sum('on_hold'));

        $list = $this->actingAs($this->tracer)->get(route('manufacturing.quality.recalls.index'))->assertOk()->viewData('page');
        $this->assertSame([$recall->number], array_column($list['props']['recalls']['data'], 'number'));
    }
}
