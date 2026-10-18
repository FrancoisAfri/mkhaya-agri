<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryAssignment;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;

/**
 * The acceptance matrix (spec §8, T-001..T-020).
 *
 * Scenarios proven elsewhere, by the test that owns the behaviour:
 *   T-003 BomImmutabilityTest (approved revision unchanged, even by raw query)
 *   T-004 PlanningTest::mrp_nets_every_level_without_double_counting_and_rounds_to_order_rules
 *   T-006 ProductionOrderTest::material_is_issued_from_the_orders_reservation_and_a_retry_counts_once
 *   T-007 AccountingControlsTest::each_event_is_in_the_subledger..., CostingTest::variances_explain...
 *   T-008 QualityTest::final_inspection_holds_output_and_an_order_cannot_close_with_it_undecided
 *   T-010 TraceabilityTest::a_recall_holds_what_is_here_accounts_for_what_has_gone...
 *   T-011 CostingTest::closing_freezes_the_variances_and_makes_the_cost_final (provisional before)
 *   T-012 AccountingControlsTest::a_closed_period_refuses_posting_and_queues_an_exception
 *   T-013 IntegrationsTest::a_retried_weighing_issues_material_once_and_a_bad_signature_is_refused
 *   T-015 PackagingTest::an_overdue_account_is_read_only_and_losing_the_module_keeps_the_history
 *   T-017 ScheduleTest::material_maintenance_and_lateness_are_exceptions
 *   T-019 TraceabilityTest::a_split_lot_carries..., ::rework_carries_the_trace_through_a_second_order
 *   T-020 AccountingControlsTest::an_authorised_reversal_keeps_the_original_and_balances_still_reconcile
 *
 * The rest are here.
 */
class AcceptanceMatrixTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $frame;

    private $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $role = Role::firstOrCreate(['name' => 'mfg test acceptance', 'guard_name' => 'web']);
        $role->syncPermissions(RoleCatalog::all());
        $this->boss = $this->makeUser('acceptance@example.test', $role);
        $this->grantModuleTier($this->boss);
        [$this->plant, $this->store, $this->steel, $this->frame] = $this->world('PLT');
    }

    /** A factory, a store with 100 kg of steel, and a frame made from 2 kg of it. */
    private function world(string $code): array
    {
        $store = $this->warehouse("W{$code}");
        $plant = $this->factory($code);
        $this->asCompany($this->company, function () use ($plant, $store) {
            $plant->update(['default_warehouse_id' => $store->id]);
            FactoryPolicy::for($plant->id, $this->company->id)->update(['require_material_for_approval' => false, 'require_routing' => false, 'require_qc_before_putaway' => false]);
        });
        $steel = FinStockItem::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'STEEL')->first() ?? $this->stockItem('STEEL', 'kg', 10);
        $frame = Item::withoutGlobalScopes()->where('company_id', $this->company->id)->first()
            ?? $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
                'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        if (! \App\Modules\Operations\Manufacturing\Models\Bom::withoutGlobalScopes()->where('item_id', $frame->id)->exists()) {
            $this->approvedBom($frame, [[$steel, 2, 0, 'kg']]);
        }
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($steel->fresh(), 100, 10, $store, ['reference' => "GRN-{$code}"]));

        return [$plant, $store, $steel, $frame];
    }

    private function released(Factory $f, FinWarehouse $w, float $qty = 10): PO
    {
        return $this->asCompany($this->company, function () use ($f, $w, $qty) {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => $qty, 'due_date' => now()->addDays(4)->toDateString(),
                'factory' => $f, 'warehouse' => $w], $this->boss);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->boss);
            $life->approve($o->fresh(), $this->boss);
            $life->release($o->fresh(), $this->boss);
            $life->start($o->fresh(), $this->boss);

            return $o->fresh();
        });
    }

    /** T-001: another company's manufacturing order cannot be opened, acted on or found. */
    public function test_t001_another_companys_order_is_denied(): void
    {
        $mine = $this->company;
        $this->company = Company::create(['name' => 'Rival Foundry']);
        app(\App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData::class)->installFor($this->company->id);
        $this->openBooks();
        $rivalUser = $this->makeUser('rival@example.test', Role::query()->where('name', 'mfg test acceptance')->first());
        [$plant, $store] = $this->world('RIV');
        $theirs = $this->asCompany($this->company, fn () => app(ProductionOrders::class)->create(['item' => Item::withoutGlobalScopes()->where('company_id', $this->company->id)->first(),
            'quantity' => 3, 'due_date' => now()->addDay()->toDateString(), 'factory' => $plant, 'warehouse' => $store], $rivalUser));
        $this->company = $mine;

        $this->actingAs($this->boss)->get(route('manufacturing.production.orders.show', $theirs->uuid))->assertNotFound();
        $this->actingAs($this->boss)->post(route('manufacturing.production.orders.plan', $theirs->uuid))->assertNotFound();
        $list = $this->actingAs($this->boss)->get(route('manufacturing.production.orders.index'))->assertOk()->viewData('page');
        $this->assertNotContains($theirs->number, array_column($list['props']['orders']['data'] ?? $list['props']['orders'], 'number'));
        $this->assertSame(PO::DRAFT, PO::withoutGlobalScopes()->find($theirs->id)->status);
    }

    /** T-002: a BOM component that does not exist, or belongs to another company, is rejected. */
    public function test_t002_an_invalid_component_is_rejected(): void
    {
        $bom = $this->draftBom($this->frame);
        $foreign = Company::create(['name' => 'Elsewhere']);
        $alien = FinStockItem::withoutGlobalScopes()->create(['company_id' => $foreign->id, 'code' => 'ALIEN', 'name' => 'Alien', 'uom' => 'kg',
            'cost_method' => 'weighted_avg', 'is_active' => true]);
        foreach ([999999999, $alien->id] as $component) {
            $this->actingAs($this->boss)->post(route('manufacturing.engineering.boms.lines.store', $bom->uuid), [
                'component_stock_item_id' => $component, 'quantity_per' => 1, 'line_kind' => 'COMPONENT'])->assertSessionHasErrors('component_stock_item_id');
        }
        $this->assertSame(0, $bom->lines()->count());
    }

    /** T-005: two orders reserving the same stock cannot hold more than there is. */
    public function test_t005_reservations_never_exceed_eligible_stock(): void
    {
        $ledger = app(StockLedger::class);
        $this->asCompany($this->company, fn () => $ledger->reserve($this->steel->fresh(), $this->store, 60, PO::SOURCE_TYPE, 1001));
        try {
            $this->asCompany($this->company, fn () => $ledger->reserve($this->steel->fresh(), $this->store, 60, PO::SOURCE_TYPE, 1002));
            $this->fail('The same stock was reserved twice over.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('free to reserve', $e->getMessage());
        }
        $this->assertEquals(40.0, $this->asCompany($this->company, fn () => $ledger->available($this->steel->fresh(), $this->store->id)));
    }

    /** T-009: finished goods are received with the right quantity, lot and stock movement. */
    public function test_t009_a_receipt_records_quantity_lot_and_movement(): void
    {
        $o = $this->released($this->plant, $this->store);
        $m = $this->asCompany($this->company, function () use ($o) {
            app(ProductionExecution::class)->issue($o, $o->components()->firstOrFail(), 20, $this->boss);

            return app(ProductionExecution::class)->receiveOutput($o->fresh(), 10, $this->boss, ['lot_number' => 'FR-0001']);
        });
        $this->assertSame('mfg_receipt', $m->type);
        $this->assertEquals(10.0, abs((float) $m->qty));
        $this->assertEquals(200.0, abs((float) $m->value), '10 at the standard material cost of 20');
        $this->assertNotNull($m->journal_id);
        $lot = FinStockLot::withoutGlobalScopes()->findOrFail($m->lot_id);
        $this->assertSame('FR-0001', $lot->lot_number);
        $this->assertSame(PO::SOURCE_TYPE, $lot->source_type);
        $this->assertSame($o->id, (int) $lot->source_id);
        $this->assertEquals(10.0, (float) FinStockPosition::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', 'AVAILABLE')->sum('qty'));
        $this->assertEquals(10.0, (float) $o->fresh()->qty_completed);
    }

    /** T-014: an answer about losses comes from records the person may see, with estimates labelled. */
    public function test_t014_ai_answers_about_losses_from_authorised_records_and_labels_estimates(): void
    {
        $this->asCompany($this->company, fn () => LossFinding::create(['company_id' => $this->company->id, 'number' => 'LP-T014', 'category' => 'HIGH_SCRAP',
            'status' => LossFinding::DETECTED, 'subject_type' => 'PRODUCT', 'subject_id' => $this->frame->id, 'subject_label' => 'FRAME',
            'measure' => 'Scrap and rejection rate', 'actual_value' => 12, 'baseline_value' => 5, 'baseline_basis' => 'rule', 'evidence' => [],
            'estimated_impact' => 840, 'confidence' => 'MEDIUM', 'impact_basis' => 'test', 'recommended_action' => 'Check the jig.',
            'dedupe_key' => 'T014', 'detected_at' => now()]));
        config(['manufacturing.assistant_narration' => false]);

        $a = $this->actingAs($this->boss)->postJson(route('manufacturing.assistant.ask'), ['question' => 'What actions could reduce our production costs?'])->assertOk()->json('answer');
        $row = collect($a['rows'])->firstWhere('records', ['LP-T014']);
        $this->assertSame('ESTIMATE', $row['kind'], 'An unverified loss is an estimate.');
        $this->assertStringContainsString('estimates, not savings', $a['summary']);

        $viewer = $this->makeUser('t014-viewer@example.test', tap(Role::firstOrCreate(['name' => 'mfg test t014', 'guard_name' => 'web']),
            fn ($r) => $r->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::USE_ASSISTANT])));
        $this->grantModuleTier($viewer);
        $refused = $this->actingAs($viewer)->postJson(route('manufacturing.assistant.ask'), ['question' => 'What actions could reduce our production costs?'])->json('answer');
        $this->assertTrue($refused['refused']);
        $this->assertSame([], $refused['rows']);
    }

    /** T-016: a factory report exported by somebody confined to one factory carries only that factory. */
    public function test_t016_an_export_carries_only_authorised_data(): void
    {
        [$other, $otherStore] = $this->world('OTH');
        foreach ([[$this->plant, $this->store, 4], [$other, $otherStore, 7]] as [$f, $w, $qty]) {
            $o = $this->released($f, $w, $qty);
            $this->asCompany($this->company, function () use ($o, $qty) {
                app(ProductionExecution::class)->issue($o, $o->components()->firstOrFail(), $qty * 2, $this->boss);
                app(ProductionExecution::class)->receiveOutput($o->fresh(), $qty, $this->boss);
            });
        }
        $site = $this->makeUser('t016-site@example.test', tap(Role::firstOrCreate(['name' => 'mfg test t016', 'guard_name' => 'web']),
            fn ($r) => $r->syncPermissions(array_values(array_diff(RoleCatalog::all(), [RoleCatalog::ALL_FACTORIES])))));
        $this->grantModuleTier($site);
        $this->asCompany($this->company, fn () => FactoryAssignment::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'user_id' => $site->id, 'granted_by' => $this->boss->id]));
        app(FactoryAccess::class)->flush();

        $mine = $this->actingAs($site)->get(route('manufacturing.reports.executive', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('kpi,output_qty,4', $mine);
        $all = $this->actingAs($this->boss)->get(route('manufacturing.reports.executive', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('kpi,output_qty,11', $all);
    }

    /** T-018: a partly completed order reconciles remaining quantity, consumed material and output. */
    public function test_t018_a_partial_completion_reconciles(): void
    {
        $o = $this->released($this->plant, $this->store);
        $this->asCompany($this->company, function () use ($o) {
            $exec = app(ProductionExecution::class);
            $exec->issue($o, $o->components()->firstOrFail(), 20, $this->boss);
            $exec->receiveOutput($o->fresh(), 6, $this->boss);
            $exec->recordLoss($o->fresh(), 1, 0, $this->boss, 'Cracked weld');
        });
        $r = $this->asCompany($this->company, fn () => app(ProductionOrderLifecycle::class)->reconciliation($o->fresh()));
        $this->assertEquals(6.0, $r['good']);
        $this->assertEquals(1.0, $r['scrapped']);
        $this->assertEquals(4.0, $r['remaining']);
        $this->assertEquals(60.0, $r['yield_percent']);
        $this->assertEquals(20.0, $r['materials'][0]['consumed']);
        $this->assertEquals(12.0, $r['materials'][0]['expected_for_output'], 'Six frames should have used 12 kg.');
        $this->assertEquals(8.0, $r['materials'][0]['variance']);
        $this->assertEquals(80.0, (float) $this->steel->fresh()->qty_on_hand);
        $this->assertSame(1, FinStockMovement::withoutGlobalScopes()->where('source_id', $o->id)->where('type', 'mfg_receipt')->count());
    }
}
