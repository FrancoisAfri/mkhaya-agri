<?php

namespace Tests\Feature\Warehouse;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturn;
use App\Modules\Operations\WarehouseManagement\Models\Evidence;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\PackageContent;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Services\CustomerReturns;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\LossControl;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseQuality;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseRecalls;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** WMS-080 returns, WMS-081 damage and wastage, WMS-082 quality control, WMS-083 recall. */
class ReturnsQualityRecallTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $shelf;

    private FinStockBin $returns;

    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('RTN', ['is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            $loc = app(LocationService::class);
            $this->shelf = $loc->create($this->wh, ['code' => 'S-01']);
            $this->returns = $loc->create($this->wh, ['code' => 'RET-1', 'kind' => 'RETURNS']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'KETTLE', 'name' => 'Kettle', 'uom' => 'ea', 'standard_cost' => 100]);
        });
        $this->inspector = $this->person('inspector', [RoleCatalog::VIEW, RoleCatalog::QUALITY]);
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function at(FinStockBin $bin, string $status): float
    {
        return (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $bin->id)->where('status', $status)->sum('qty');
    }

    private function rma(float $qty = 2, array $extra = []): CustomerReturn
    {
        return $this->in(fn () => app(CustomerReturns::class)->authorise($extra + ['warehouse_id' => $this->wh->id, 'reference' => 'Phone call 14 Oct',
            'customer_name' => 'Sipho', 'lines' => [['item_id' => $this->item->id, 'qty' => $qty, 'reason_code' => 'DEFECTIVE']]], $this->manager));
    }

    private function decide(Inspection $inspection, string $decision, ?string $reason = 'Checked'): void
    {
        $this->in(function () use ($inspection, $decision, $reason) {
            $qc = app(QualityControl::class);
            $qc->record($inspection->fresh(), [['name' => 'Visual check', 'passed' => $decision !== 'SCRAP']], $this->inspector);
            $qc->dispose($inspection->fresh(), $decision, $reason, $this->inspector);
        });
    }

    // ── WMS-080 customer returns ─────────────────────────────────────────────────────────────

    public function test_a_return_is_received_into_quarantine_at_cost_and_inspected_before_it_is_stock(): void
    {
        $rma = $this->rma(2);
        $line = $rma->lines()->first();

        $this->in(fn () => app(CustomerReturns::class)->receive($rma, [$line->id => ['qty' => 2, 'condition' => 'OPENED']], $this->manager));

        $this->assertEquals(2, $this->at($this->returns, 'QUARANTINE'), 'An uninspected return became available.');
        $this->assertEquals(0, $this->at($this->returns, 'AVAILABLE'));
        $movement = FinStockMovement::withoutGlobalScopes()->where('source_type', 'WMS_RETURN')->firstOrFail();
        $this->assertSame('cust_return', $movement->type);
        $this->assertEquals(200, (float) $movement->value, 'The return was not valued at the cost it left at.');
        $line = $line->fresh();
        $this->assertSame('QUARANTINED', $line->outcome);
        $inspection = Inspection::query()->findOrFail($line->inspection_id);
        $this->assertSame(InspectionPlan::RETURN, $inspection->stage);
        $this->assertSame(CustomerReturn::INSPECTING, $rma->fresh()->status);

        $this->decide($inspection, 'ACCEPT');

        $this->assertEquals(2, $this->at($this->returns, 'AVAILABLE'));
        $this->assertSame('RESTOCKED', $line->fresh()->outcome);
        $this->assertSame(CustomerReturn::COMPLETED, $rma->fresh()->status);
        $this->assertTrue(PutawayTask::query()->where('source_type', 'WMS_RETURN')->where('from_bin_id', $this->returns->id)->exists(), 'Restocked goods were left in the returns bay.');
    }

    public function test_a_scrapped_return_is_disposed_of_and_a_repaired_one_is_inspected_again(): void
    {
        $rma = $this->rma(3, ['lines' => [['item_id' => $this->item->id, 'qty' => 1], ['item_id' => $this->item->id, 'qty' => 2]]]);
        [$a, $b] = $rma->lines()->get()->all();
        $this->in(fn () => app(CustomerReturns::class)->receive($rma, [$a->id => ['qty' => 1, 'condition' => 'DAMAGED'], $b->id => ['qty' => 2, 'condition' => 'DEFECTIVE']], $this->manager));

        $this->decide(Inspection::query()->findOrFail($a->fresh()->inspection_id), 'SCRAP', 'Cracked base');
        $this->decide(Inspection::query()->findOrFail($b->fresh()->inspection_id), 'REWORK', 'Replace the switch');

        $this->assertSame('DISPOSED', $a->fresh()->outcome);
        $this->assertSame('REPAIR', $b->fresh()->outcome);
        $this->assertNotEquals($b->inspection_id, $b->fresh()->inspection_id, 'Repaired goods were not sent back to inspection.');
        $this->assertEquals(2, $this->at($this->returns, 'QUARANTINE'));
        $this->assertSame(CustomerReturn::INSPECTING, $rma->fresh()->status);
    }

    public function test_unopened_goods_go_straight_back_to_stock_where_the_warehouse_allows_it(): void
    {
        $this->wh->update(['policies' => ['scan_to_confirm' => false, 'returns_require_inspection' => false]]);
        $rma = $this->rma(1);
        $line = $rma->lines()->first();

        $this->in(fn () => app(CustomerReturns::class)->receive($rma->fresh(), [$line->id => ['qty' => 1, 'condition' => 'UNOPENED']], $this->manager));

        $this->assertEquals(1, $this->at($this->returns, 'AVAILABLE'));
        $this->assertNull($line->fresh()->inspection_id);
        $this->assertSame(CustomerReturn::COMPLETED, $rma->fresh()->status);
    }

    public function test_nobody_returns_more_than_the_order_sent_and_a_return_is_credited_once(): void
    {
        $order = $this->in(function () {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'SO-RET', [['item_id' => $this->item->id, 'qty' => 5]], ['customer_name' => 'Sipho'], $this->manager);
            $o->lines()->update(['qty_allocated' => 3, 'qty_picked' => 3, 'qty_packed' => 3, 'qty_dispatched' => 3]);

            return $o;
        });

        try {
            $this->rma(4, ['order_id' => $order->id]);
            $this->fail('More was returned than was dispatched.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dispatched 3', $e->getMessage());
        }
        $rma = $this->rma(3, ['order_id' => $order->id]);

        try {
            $this->in(fn () => app(CustomerReturns::class)->credit($rma, 100, $this->manager));
            $this->fail('A return was credited without an invoice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invoice', $e->getMessage());
        }

        // Against a real sales invoice: one credit note, and never a second.
        $invoice = $this->in(function () {
            $party = \App\Modules\Finance\Accounting\Models\FinParty::create(['company_id' => $this->company->id, 'kind' => 'customer', 'source' => 'manual',
                'code' => 'C'.uniqid(), 'name' => 'Sipho']);

            return app(\App\Modules\Finance\Accounting\Services\ArApService::class)->createInvoice(['party_id' => $party->id, 'direction' => 'receivable',
                'lines' => [['account_id' => \App\Modules\Finance\Accounting\Models\FinAccount::where('code', '4100')->value('id'), 'description' => 'Kettles', 'unit_price' => 900, 'tax_rate' => 0]]]);
        });
        $rma->update(['invoice_id' => $invoice->id, 'status' => CustomerReturn::RECEIVED]);
        $cn = $this->in(fn () => app(CustomerReturns::class)->credit($rma->fresh(), 600, $this->manager));
        $this->assertSame('credit_note', $cn->doc_type);
        $this->assertEquals(600, (float) $rma->fresh()->credit_amount);

        $this->expectExceptionMessage('already been credited');
        $this->in(fn () => app(CustomerReturns::class)->credit($rma->fresh(), 100, $this->manager));
    }

    // ── WMS-082 quality control ──────────────────────────────────────────────────────────────

    public function test_stock_held_in_its_bin_is_released_or_scrapped_by_the_inspection(): void
    {
        $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 10, 100, $this->wh, ['bin_id' => $this->shelf->id]));
        $first = $this->in(fn () => app(WarehouseQuality::class)->hold($this->wh, $this->item, $this->shelf, null, 4, 'Dented boxes', $this->manager));
        $this->assertEquals(4, $this->at($this->shelf, 'ON_HOLD'));
        $this->assertSame($this->shelf->id, $first->bin_id);

        $this->decide($first, 'SCRAP', 'Crushed');
        $this->assertEquals(0, $this->at($this->shelf, 'ON_HOLD'));
        $this->assertEquals(6, $this->at($this->shelf, 'AVAILABLE'));

        $second = $this->in(fn () => app(WarehouseQuality::class)->hold($this->wh, $this->item, $this->shelf, null, 2, 'Label smudged', $this->manager));
        $this->decide($second, 'ACCEPT');
        $this->assertEquals(6, $this->at($this->shelf, 'AVAILABLE'));
    }

    public function test_an_order_with_a_dispatch_plan_cannot_leave_until_it_passes(): void
    {
        $this->in(fn () => InspectionPlan::create(['company_id' => $this->company->id, 'code' => 'DSP', 'name' => 'Outbound check', 'stage' => 'DISPATCH',
            'stock_item_id' => $this->item->id, 'sample_size' => 1, 'is_active' => true]));
        $order = $this->in(fn () => app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'SO-QC', [['item_id' => $this->item->id, 'qty' => 1]], [], $this->manager)[0]);

        $this->assertStringContainsString('KETTLE', $this->in(fn () => app(WarehouseQuality::class)->dispatchRefusal($order)));
        $opened = $this->in(fn () => app(WarehouseQuality::class)->inspectOrder($order, $this->inspector));
        $this->assertCount(1, $opened);
        $this->assertCount(0, $this->in(fn () => app(WarehouseQuality::class)->inspectOrder($order, $this->inspector)), 'A second inspection was opened.');

        $this->decide($opened[0], 'ACCEPT');
        $this->assertNull($this->in(fn () => app(WarehouseQuality::class)->dispatchRefusal($order)));
    }

    public function test_a_plan_samples_by_its_rule_and_a_failed_check_carries_a_defect_code(): void
    {
        $plan = $this->in(fn () => InspectionPlan::create(['company_id' => $this->company->id, 'code' => 'RTN', 'name' => 'Returns', 'stage' => 'RETURN',
            'sampling_rule' => 'SQRT', 'is_active' => true]));
        $this->assertSame(5, $plan->sampleFor(16));
        $plan->update(['sampling_rule' => 'PERCENT', 'sample_pct' => 10]);
        $this->assertSame(3, $plan->fresh()->sampleFor(25));
        $plan->update(['sampling_rule' => 'SQRT']);
        $this->assertSame(2, $plan->fresh()->sampleFor(2), 'More samples than units.');

        DB::table('mfg_defect_codes')->insert(['company_id' => $this->company->id, 'code' => 'SCRATCH', 'name' => 'Scratched', 'severity' => 'MINOR', 'created_at' => now(), 'updated_at' => now()]);
        $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 3, 100, $this->wh, ['bin_id' => $this->shelf->id]));
        $inspection = $this->in(fn () => app(WarehouseQuality::class)->hold($this->wh, $this->item, $this->shelf, null, 1, 'Customer complaint', $this->manager));
        $this->in(fn () => app(QualityControl::class)->record($inspection, [['name' => 'Finish', 'passed' => false, 'defect_code' => 'SCRATCH']], $this->inspector));

        $this->assertNotNull(DB::table('mfg_inspection_results')->where('inspection_id', $inspection->id)->value('defect_code_id'));
    }

    // ── WMS-081 damage and wastage ───────────────────────────────────────────────────────────

    public function test_breakage_needs_a_photograph_and_a_high_risk_item_needs_a_second_person(): void
    {
        $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 10, 100, $this->wh, ['bin_id' => $this->shelf->id]));
        $breakage = $this->in(fn () => ReasonCode::query()->where('code', 'BREAKAGE')->firstOrFail());
        $ask = fn (array $extra = []) => $this->in(fn () => app(StockAdjustments::class)->request($extra + ['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id,
            'bin_id' => $this->shelf->id, 'qty' => -1, 'reason_code_id' => $breakage->id], $this->manager));

        try {
            $ask();
            $this->fail('Breakage was written off without evidence.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }
        $photo = UploadedFile::fake()->createWithContent('shelf.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
        $posted = $ask(['evidence' => [$photo]]);
        $this->assertSame(StockAdjustment::POSTED, $posted->status);
        $this->assertSame(1, Evidence::query()->where('subject_type', 'ADJUSTMENT')->where('subject_id', $posted->id)->count());

        DB::table('wms_item_profiles')->updateOrInsert(['stock_item_id' => $this->item->id], ['company_id' => $this->company->id, 'loss_risk' => 'HIGH']);
        $risky = $ask(['evidence' => [$photo]]);
        $this->assertSame(StockAdjustment::PENDING, $risky->status);
        $this->assertContains('high-risk item', $risky->approval_reasons);
    }

    public function test_recoveries_are_capped_at_the_loss_and_the_register_keeps_suspected_apart_from_confirmed(): void
    {
        $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 10, 100, $this->wh, ['bin_id' => $this->shelf->id]));
        $code = fn ($c) => $this->in(fn () => ReasonCode::query()->where('code', $c)->firstOrFail()->id);
        $damaged = $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id,
            'bin_id' => $this->shelf->id, 'qty' => -2, 'reason_code_id' => $code('DAMAGED')], $this->manager));
        $theft = $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id,
            'bin_id' => $this->shelf->id, 'qty' => -1, 'reason_code_id' => $code('THEFT')], $this->manager));
        $this->assertSame(StockAdjustment::PENDING, $theft->status);

        $loss = app(LossControl::class);
        $claim = $this->in(fn () => $loss->openRecovery($damaged, ['kind' => 'INSURANCE', 'amount_claimed' => 150], $this->manager));
        try {
            $this->in(fn () => $loss->openRecovery($damaged, ['kind' => 'CARRIER', 'amount_claimed' => 100], $this->manager));
            $this->fail('More was claimed than was lost.');
        } catch (RuntimeException) {
        }
        $this->in(fn () => $loss->progress($claim, 'SETTLED', 120, 'Paid less excess'));

        $r = $this->in(fn () => $loss->register($this->wh->id, now()->toDateString(), now()->toDateString()));
        $this->assertEquals(200, $r['confirmed']['total']);
        $this->assertEquals(100, $r['suspected']['pending_value'], 'A loss awaiting approval was counted as confirmed or ignored.');
        $this->assertEquals(120, $r['recoveries']['recovered']);
        $this->assertEquals(80, $r['recoveries']['net_confirmed']);
    }

    // ── WMS-083 recall ───────────────────────────────────────────────────────────────────────

    public function test_a_serial_range_recall_holds_the_units_and_finds_the_customers_who_have_the_rest(): void
    {
        $ledger = app(StockLedger::class);
        $lots = $this->in(fn () => collect(['SN-0098', 'SN-0099', 'SN-0100', 'SN-0101'])->map(fn ($n) => $ledger->lot($this->item->fresh(), $n, ['kind' => 'SERIAL'])));
        foreach ($lots as $lot) {
            $this->in(fn () => $ledger->receive($this->item->fresh(), 1, 100, $this->wh, ['bin_id' => $this->shelf->id, 'lot_id' => $lot->id]));
        }
        // SN-0100 went to a customer.
        $this->in(function () use ($lots) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'SO-77', [['item_id' => $this->item->id, 'qty' => 1]], ['customer_name' => 'Thandi Stores'], $this->manager);
            $p = Package::create(['company_id' => $this->company->id, 'order_id' => $o->id, 'sscc' => '00'.Str::random(16), 'status' => 'CLOSED']);
            PackageContent::create(['company_id' => $this->company->id, 'package_id' => $p->id, 'order_line_id' => $o->lines()->first()->id, 'item_id' => $this->item->id, 'lot_id' => $lots[2]->id, 'qty' => 1]);
            $s = Shipment::create(['company_id' => $this->company->id, 'number' => 'SHP-1', 'order_id' => $o->id, 'warehouse_id' => $this->wh->id, 'status' => 'DISPATCHED', 'dispatched_at' => now()]);
            DB::table('wms_shipment_packages')->insert(['company_id' => $this->company->id, 'shipment_id' => $s->id, 'package_id' => $p->id]);
            app(StockLedger::class)->scrap($this->item->fresh(), 1, $this->wh, ['bin_id' => $this->shelf->id, 'lot_id' => $lots[2]->id, 'reason' => 'stand-in for the dispatch', 'source_type' => 'TEST', 'source_id' => 1]);
        });

        $recall = $this->in(fn () => app(WarehouseRecalls::class)->open(['scope' => 'SERIALS', 'item_id' => $this->item->id, 'serial_from' => 'SN-0099',
            'serial_to' => 'SN-0100', 'title' => 'Faulty element', 'reason' => 'Overheating reports', 'severity' => 'CLASS_II'], $this->manager));
        $this->assertSame('SERIALS', $recall->scope);
        $this->assertEqualsCanonicalizing([$lots[1]->id, $lots[2]->id], $recall->lots()->pluck('lot_id')->all());

        $held = $this->in(fn () => app(WarehouseRecalls::class)->holdAll($recall, $this->manager));
        $this->assertEquals(1, $held, 'Only SN-0099 is still here to hold.');
        $this->assertEquals(1, (float) FinStockPosition::withoutGlobalScopes()->where('lot_id', $lots[1]->id)->where('status', 'ON_HOLD')->sum('qty'));
        $this->assertEquals(1, (float) FinStockPosition::withoutGlobalScopes()->where('lot_id', $lots[0]->id)->where('status', 'AVAILABLE')->sum('qty'), 'A unit outside the range was held.');

        $impact = $this->in(fn () => app(WarehouseRecalls::class)->impact($recall->fresh()));
        $this->assertSame('Thandi Stores', $impact['customers'][0]['customer']);
        $this->assertSame(['NOTIFY'], collect($impact['queue'])->pluck('kind')->unique()->values()->all());
    }

    public function test_a_whole_product_recall_blocks_the_product_and_closing_it_gives_back_its_status(): void
    {
        $this->in(fn () => app(StockLedger::class)->receive($this->item->fresh(), 5, 100, $this->wh, ['bin_id' => $this->shelf->id]));

        $recall = $this->in(fn () => app(WarehouseRecalls::class)->open(['scope' => 'ITEM', 'item_id' => $this->item->id, 'title' => 'All kettles',
            'reason' => 'Supplier notice', 'severity' => 'CLASS_I'], $this->manager));
        $this->assertSame(FinStockItem::RECALLED, $this->item->fresh()->status);
        $this->assertNotNull($this->item->fresh()->refusalFor('dispatch'));
        $impact = $this->in(fn () => app(WarehouseRecalls::class)->impact($recall));
        $this->assertEquals(5, $impact['totals']['here'], 'Unlotted stock of the product was not found.');

        // A recalled kettle can still come back from a customer.
        $rma = $this->rma(1);
        $this->in(fn () => app(CustomerReturns::class)->receive($rma, [$rma->lines()->first()->id => ['qty' => 1, 'condition' => 'UNOPENED']], $this->manager));
        $this->assertEquals(1, $this->at($this->returns, 'QUARANTINE'), 'A recalled product was not quarantined on return.');

        $this->in(fn () => app(WarehouseRecalls::class)->close($recall, 'Supplier withdrew the notice', $this->manager));
        $this->assertSame(FinStockItem::ACTIVE, $this->item->fresh()->status);
    }

    // ── The screens ──────────────────────────────────────────────────────────────────────────

    public function test_the_return_inspection_and_recall_screens_work_end_to_end(): void
    {
        $item = $this->item;
        $this->actingAs($this->manager)->post(route('warehouse.returns.store'), ['warehouse' => $this->wh->uuid, 'reference' => 'Email 3',
            'lines' => [['item' => $item->uuid, 'qty' => 1, 'reason_code' => 'DAMAGED']]])->assertSessionHasNoErrors();
        $rma = CustomerReturn::query()->latest('id')->firstOrFail();
        $line = $rma->lines()->first();
        $this->actingAs($this->viewer)->post(route('warehouse.returns.act', $rma->uuid), ['action' => 'receive'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('warehouse.returns.act', $rma->uuid), ['action' => 'receive',
            'lines' => [$line->id => ['qty' => 1, 'condition' => 'DAMAGED']]])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get(route('warehouse.returns.show', $rma->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Returns/Show')->where('lines.0.outcome', 'QUARANTINED'));

        $inspection = Inspection::query()->findOrFail($line->fresh()->inspection_id);
        $this->actingAs($this->inspector)->get(route('warehouse.quality.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Quality/Inspections')->where('inspections.data.0.number', $inspection->number));
        $this->actingAs($this->viewer)->post(route('warehouse.quality.record', $inspection->uuid), ['results' => [['name' => 'Box', 'passed' => true]]])->assertForbidden();
        $this->actingAs($this->inspector)->post(route('warehouse.quality.record', $inspection->uuid), ['results' => [['name' => 'Box', 'passed' => true]]])->assertSessionHasNoErrors();
        $this->actingAs($this->inspector)->post(route('warehouse.quality.decide', $inspection->uuid), ['decision' => 'ACCEPT'])->assertSessionHasNoErrors();
        $this->assertSame('RESTOCKED', $line->fresh()->outcome);

        $this->actingAs($this->manager)->post(route('warehouse.recalls.store'), ['scope' => 'ITEM', 'item' => $item->uuid, 'title' => 'Kettles',
            'reason' => 'Notice', 'severity' => 'CLASS_III'])->assertSessionDoesntHaveErrors(['refused', 'scope', 'item', 'title', 'reason', 'severity']);
        $recall = \App\Modules\Operations\Manufacturing\Models\Recall::query()->latest('id')->firstOrFail();
        $this->actingAs($this->viewer)->get(route('warehouse.recalls.show', $recall->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Recalls/Show')->where('impact.totals.here', 1));
        $this->actingAs($this->viewer)->get(route('warehouse.recalls.report', $recall->uuid))->assertForbidden();
        $this->actingAs($this->manager)->get(route('warehouse.recalls.report', $recall->uuid))->assertOk();
        $this->actingAs($this->manager)->post(route('warehouse.recalls.act', $recall->uuid), ['action' => 'close', 'reason' => 'Done'])->assertSessionHasNoErrors();

        foreach (['warehouse.returns.index', 'warehouse.quality.setup', 'warehouse.losses.index', 'warehouse.recalls.index'] as $page) {
            $this->actingAs($this->viewer)->get(route($page))->assertOk();
        }
        $this->actingAs($this->viewer)->post(route('warehouse.quality.plans.store'), ['code' => 'X'])->assertForbidden();
    }

    /** QA-012: a batch recall finds the stock on the shelf, on the road between warehouses, and with customers. */
    public function test_a_batch_recall_finds_stock_on_hand_in_transit_and_downstream(): void
    {
        $ledger = app(StockLedger::class);
        $other = $this->warehouse('RT2', ['is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $lot = $this->in(fn () => $ledger->lot($this->item->fresh(), 'B-2026-07'));
        $this->in(fn () => $ledger->receive($this->item->fresh(), 10, 100, $this->wh, ['bin_id' => $this->shelf->id, 'lot_id' => $lot->id]));
        // Two on a truck to the other warehouse.
        $this->in(function () use ($other, $lot) {
            $svc = app(\App\Modules\Operations\WarehouseManagement\Services\TransferOrders::class);
            $o = $svc->draft($this->wh, $other, [['item_id' => $this->item->id, 'qty' => 2, 'from_bin_id' => $this->shelf->id, 'lot_id' => $lot->id]], $this->manager);
            $svc->approve($o, $this->manager);
            $svc->pick($o->fresh('lines'), [], $this->manager);
            $svc->dispatch($o->fresh('lines'), $this->manager);
        });
        // One with a customer.
        $this->in(function () use ($lot) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'SO-99', [['item_id' => $this->item->id, 'qty' => 1]], ['customer_name' => 'Sipho Hardware'], $this->manager);
            $p = Package::create(['company_id' => $this->company->id, 'order_id' => $o->id, 'sscc' => '00'.Str::random(16), 'status' => 'CLOSED']);
            PackageContent::create(['company_id' => $this->company->id, 'package_id' => $p->id, 'order_line_id' => $o->lines()->first()->id, 'item_id' => $this->item->id, 'lot_id' => $lot->id, 'qty' => 1]);
            $sh = Shipment::create(['company_id' => $this->company->id, 'number' => 'SHP-9', 'order_id' => $o->id, 'warehouse_id' => $this->wh->id, 'status' => 'DISPATCHED', 'dispatched_at' => now()]);
            DB::table('wms_shipment_packages')->insert(['company_id' => $this->company->id, 'shipment_id' => $sh->id, 'package_id' => $p->id]);
            app(StockLedger::class)->dispatchToCustomer($this->item->fresh(), 1, $this->wh, ['bin_id' => $this->shelf->id, 'lot_id' => $lot->id, 'source_type' => 'TEST', 'source_id' => 1]);
        });

        $recall = $this->in(fn () => app(WarehouseRecalls::class)->open(['scope' => 'LOT', 'lot_id' => $lot->id, 'title' => 'Seal failure', 'reason' => 'Leaks reported', 'severity' => 'CLASS_I'], $this->manager));
        $impact = $this->in(fn () => app(WarehouseRecalls::class)->impact($recall->fresh()));

        $this->assertEquals(7, collect($impact['stock'])->where('in_transit', false)->sum('qty'), 'on hand');
        $this->assertEquals(2, collect($impact['stock'])->where('in_transit', true)->sum('qty'), 'in transit');
        $this->assertSame('Sipho Hardware', $impact['customers'][0]['customer']);
        $this->assertEquals(1, $impact['totals']['shipped']);
        $this->in(fn () => app(WarehouseRecalls::class)->holdAll($recall->fresh(), $this->manager));
        $after = $this->in(fn () => app(WarehouseRecalls::class)->impact($recall->fresh()));
        $this->assertContains('TRANSIT', collect($after['queue'])->pluck('kind')->all(), 'the truck is to be intercepted');
        $this->assertContains('NOTIFY', collect($after['queue'])->pluck('kind')->all());
    }
}
