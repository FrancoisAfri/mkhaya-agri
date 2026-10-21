<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Nonconformance;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\Nonconformances;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * MFG-009 -- quality built into production: incoming, in-process and final inspection on real
 * stock, dispositions that move it, NCRs with corrective action, and the screens that drive them.
 */
class QualityTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private FinStockItem $steel;

    private FinStockItem $bolt;

    private $inspector;

    private $qualityManager;

    private int $supplierId;

    private int $poId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $inspector = Role::firstOrCreate(['name' => 'mfg test inspector', 'guard_name' => 'web']);
        $inspector->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_QUALITY, RoleCatalog::INSPECT]);
        $manager = Role::firstOrCreate(['name' => 'mfg test quality manager', 'guard_name' => 'web']);
        $manager->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_QUALITY, RoleCatalog::DISPOSE,
            RoleCatalog::MANAGE_QUALITY_PLANS, RoleCatalog::MANAGE_NCR, RoleCatalog::EXECUTE, RoleCatalog::RELEASE_ORDERS, RoleCatalog::CLOSE_ORDERS]);
        $this->inspector = $this->makeUser('mfg-inspector@example.test', $inspector);
        $this->qualityManager = $this->makeUser('mfg-qm@example.test', $manager);
        $this->grantModuleTier($this->inspector);
        $this->grantModuleTier($this->qualityManager);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, fn () => Shift::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
            'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true,
        ]));

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->bolt = $this->stockItem('BOLT', 'ea', 2);
        $fg = $this->stockItem('WIDGET', 'ea', 0);
        $this->widget = $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $fg->id, 'kind' => 'FINISHED',
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true,
        ]));
        $this->approvedBom($this->widget, [[$this->steel, 2, 0, 'kg'], [$this->bolt, 4, 0, 'ea']]);
        $centre = $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'ASM', 'name' => 'Assembly',
            'cost_per_hour' => 120, 'labour_rate_per_hour' => 60, 'is_active' => true,
        ]));
        $this->routing($this->widget, [
            ['Prepare', 10, 1, ['work_centre_id' => $centre->id]],
            ['Assemble', 15, 3, ['work_centre_id' => $centre->id, 'is_inspection_point' => true]],
            ['Pack', 5, 1, ['work_centre_id' => $centre->id]],
        ], [], true);

        $this->supplierId = (int) DB::table('suppliers')->insertGetId(['company_name' => 'Steel Mills (Pty) Ltd', 'company_id' => $this->company->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->poId = (int) DB::table('purchase_orders')->insertGetId(['po_number' => 'PO-7001', 'supplier_id' => $this->supplierId,
            'title' => 'Steel', 'company_id' => $this->company->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function quality(): QualityControl { return app(QualityControl::class); }

    private function plan(string $stage, array $attributes = [], array $checks = []): InspectionPlan
    {
        return $this->asCompany($this->company, function () use ($stage, $attributes, $checks) {
            $plan = InspectionPlan::create(array_merge([
                'company_id' => $this->company->id, 'code' => $stage.'-'.uniqid(), 'name' => "$stage plan", 'stage' => $stage,
                'item_id' => $stage === 'INCOMING' ? null : $this->widget->id,
                'stock_item_id' => $stage === 'INCOMING' ? $this->steel->id : null,
                'blocks_production' => true, 'is_active' => true,
            ], $attributes));
            foreach ($checks as $i => $c) {
                $plan->characteristics()->create(array_merge(['company_id' => $this->company->id, 'sequence' => ($i + 1) * 10, 'kind' => 'MEASURED'], $c));
            }

            return $plan->fresh('characteristics');
        });
    }

    private function receiveSteel(float $qty = 100, string $lot = 'HEAT-1'): Inspection
    {
        return $this->asCompany($this->company, fn () => $this->quality()->receiveForInspection([
            'stock_item' => $this->steel->fresh(), 'warehouse' => $this->store, 'qty' => $qty, 'unit_cost' => 10,
            'lot_number' => $lot, 'supplier_id' => $this->supplierId, 'purchase_order_id' => $this->poId, 'supplier_lot_number' => 'SM-88',
        ], $this->inspector));
    }

    private function usable(FinStockItem $item): float
    {
        return app(StockLedger::class)->available($item->fresh(), $this->store->id);
    }

    private function statusQty(FinStockItem $item, string $status): float
    {
        return (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $item->id)->where('status', $status)->sum('qty');
    }

    // ── Incoming ────────────────────────────────────────────────────────────────────────────

    public function test_received_material_is_quarantined_and_cannot_be_issued_or_dispatched_until_decided(): void
    {
        $this->plan('INCOMING', [], [['name' => 'Thickness', 'uom' => 'mm', 'lower_limit' => 4.9, 'upper_limit' => 5.1]]);
        $inspection = $this->receiveSteel();

        $this->assertSame('INCOMING', $inspection->stage);
        $this->assertNotNull($inspection->plan_id, 'The supplier-material plan was not picked up.');
        $this->assertEquals(100.0, $this->statusQty($this->steel, 'QUARANTINE'));
        $this->assertEquals(0.0, $this->usable($this->steel));

        // A plain stock issue -- a dispatch -- refuses it.
        try {
            $this->asCompany($this->company, fn () => DB::transaction(fn () => app(InventoryService::class)
                ->issue($this->steel->fresh(), 5, null, 'DN-1', $this->store)));
            $this->fail('Quarantined stock was issued.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('quarantined', $e->getMessage());
        }

        $lot = app(StockLedger::class)->lot($this->steel->fresh(), 'HEAT-1');
        $this->assertSame($this->supplierId, (int) $lot->supplier_id);
        $this->assertSame('SM-88', $lot->supplier_lot_number);
    }

    public function test_a_failed_measurement_is_flagged_raises_an_ncr_against_the_supplier_and_cannot_simply_be_accepted(): void
    {
        $plan = $this->plan('INCOMING', ['sample_size' => 2], [['name' => 'Thickness', 'uom' => 'mm', 'lower_limit' => 4.9, 'upper_limit' => 5.1, 'is_critical' => true]]);
        $inspection = $this->receiveSteel();
        $c = $plan->characteristics->first()->uuid;

        // Two samples are required.
        try {
            $this->asCompany($this->company, fn () => $this->quality()->record($inspection, [['characteristic' => $c, 'value' => 5.0]], $this->inspector));
            $this->fail('An inspection was recorded with fewer samples than its plan asks for.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('needs 2 results', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $this->quality()->record($inspection, [
            ['characteristic' => $c, 'sample_no' => 1, 'value' => 5.0],
            ['characteristic' => $c, 'sample_no' => 2, 'value' => 5.4],
        ], $this->inspector));
        $inspection->refresh();

        $this->assertSame('FAILED', $inspection->result, 'The out-of-limit value was not flagged.');
        $this->assertSame('AWAITING_DISPOSITION', $inspection->status);
        $ncr = Nonconformance::withoutGlobalScopes()->findOrFail($inspection->ncr_id);
        $this->assertSame('MAJOR', $ncr->severity, 'A failed critical check is a major non-conformance.');
        $this->assertSame($this->supplierId, (int) $ncr->supplier_id);
        $this->assertSame($this->poId, (int) $ncr->purchase_order_id);
        $this->assertStringStartsWith('NCR-', $ncr->number);

        $this->assertNotContains('ACCEPT', $this->quality()->allowedDispositions($inspection));
        try {
            $this->asCompany($this->company, fn () => $this->quality()->dispose($inspection, 'CONDITIONAL_ACCEPT', null, $this->qualityManager));
            $this->fail('A concession was made without a reason.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $this->quality()->dispose($inspection, 'RETURN_TO_SUPPLIER', 'Out of thickness tolerance', $this->qualityManager));
        $this->assertEquals(100.0, $this->statusQty($this->steel, 'REJECTED'));
        $this->assertEquals(0.0, $this->statusQty($this->steel, 'QUARANTINE'));
        $this->assertSame('CLOSED', $inspection->fresh()->status);

        // What was measured is now evidence.
        try {
            DB::transaction(fn () => DB::table('mfg_inspection_results')->where('inspection_id', $inspection->id)->update(['measured_value' => 5.0]));
            $this->fail('A closed inspection\'s results were edited.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }
    }

    public function test_a_passed_incoming_inspection_is_released_only_by_a_decision_and_a_required_certificate(): void
    {
        $plan = $this->plan('INCOMING', ['requires_certificate' => true], [['name' => 'Visual', 'kind' => 'ATTRIBUTE']]);
        $inspection = $this->receiveSteel(50);
        $this->asCompany($this->company, fn () => $this->quality()->record($inspection,
            [['characteristic' => $plan->characteristics->first()->uuid, 'passed' => true]], $this->inspector));

        $this->assertSame('PASSED', $inspection->fresh()->result);
        $this->assertEquals(0.0, $this->usable($this->steel), 'A pass released the stock without a decision.');

        try {
            $this->asCompany($this->company, fn () => $this->quality()->dispose($inspection->fresh(), 'ACCEPT', null, $this->qualityManager));
            $this->fail('Stock was released without the certificate the plan requires.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('certificate', $e->getMessage());
        }

        $this->asCompany($this->company, function () use ($inspection) {
            $doc = \App\Modules\Operations\DocumentManagement\Models\Document::create([
                'title' => 'Mill certificate', 'status' => \App\Modules\Operations\DocumentManagement\Models\Document::STATUS_ACTIVE, 'version_count' => 1,
            ]);
            $inspection->attachDocument($doc);
            $this->quality()->dispose($inspection->fresh(), 'ACCEPT', null, $this->qualityManager);
        });

        $this->assertEquals(50.0, $this->usable($this->steel));
    }

    public function test_held_stock_can_be_scrapped_to_scrap_and_spoilage(): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->bolt->fresh(), 40, 2, $this->store, ['reference' => 'GRN']));
        $inspection = $this->asCompany($this->company, fn () => $this->quality()->holdForInspection(
            $this->bolt->fresh(), $this->store, 10, null, 'FINAL', 'Customer complaint: stripped threads', $this->inspector));

        $this->assertEquals(10.0, $this->statusQty($this->bolt, 'ON_HOLD'));
        $this->assertEquals(30.0, $this->usable($this->bolt));

        $this->asCompany($this->company, function () use ($inspection) {
            $this->quality()->record($inspection, [['name' => 'Thread gauge', 'passed' => false]], $this->inspector);
            $this->quality()->dispose($inspection->fresh(), 'SCRAP', 'Threads stripped', $this->qualityManager);
        });

        $this->assertEquals(0.0, $this->statusQty($this->bolt, 'ON_HOLD'));
        $this->assertEquals(30.0, (float) $this->bolt->fresh()->qty_on_hand);
        $scrapped = FinJournalLine::query()->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', '5610')->sum('fin_journal_lines.debit');
        $this->assertEquals(20.0, (float) $scrapped);
    }

    // ── In process ──────────────────────────────────────────────────────────────────────────

    private function releasedOrder(float $qty = 10): PO
    {
        $this->asCompany($this->company, function () {
            app(StockLedger::class)->receive($this->steel->fresh(), 100, 10, $this->store, ['reference' => 'GRN']);
            app(StockLedger::class)->receive($this->bolt->fresh(), 200, 2, $this->store, ['reference' => 'GRN']);
        });

        return $this->asCompany($this->company, function () use ($qty) {
            $order = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => $qty,
                'due_date' => now()->addDays(10)->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->engineer);
            $life->approve($order, $this->approver);
            $life->release($order, $this->approver);

            return $order->fresh();
        });
    }

    public function test_an_inspection_point_opens_its_inspections_by_interval_and_a_failure_stops_production(): void
    {
        $assemble = $this->asCompany($this->company, fn () => Routing::where('item_id', $this->widget->id)->where('status', 'APPROVED')->first()
            ->operations()->where('name', 'Assemble')->first());
        $plan = $this->plan('IN_PROCESS', ['routing_operation_id' => $assemble->id, 'every_qty' => 5],
            [['name' => 'Torque', 'uom' => 'Nm', 'lower_limit' => 20, 'upper_limit' => 25]]);
        $torque = $plan->characteristics->first()->uuid;

        $order = $this->releasedOrder(10);
        $ops = $order->operations()->get()->keyBy('name');
        $floor = app(ProductionExecution::class);

        $this->asCompany($this->company, function () use ($floor, $order, $ops) {
            $floor->completeOperation($order, $ops['Prepare'], ['completed' => 10], $this->engineer);
            $floor->completeOperation($order, $ops['Assemble']->fresh(), ['completed' => 10], $this->engineer);
        });

        $inspections = Inspection::withoutGlobalScopes()->where('order_operation_id', $ops['Assemble']->id)->orderBy('id')->get();
        $this->assertCount(2, $inspections, 'Ten units at one inspection every five should open two.');

        // The gate is the inspections' to decide, not a hand-recorded pass.
        try {
            $this->asCompany($this->company, fn () => $floor->recordGate($order, $ops['Assemble']->fresh(), 'PASSED', $this->engineer));
            $this->fail('A planned inspection point was passed by hand.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('decided by its inspections', $e->getMessage());
        }

        $this->asCompany($this->company, function () use ($inspections, $torque) {
            $this->quality()->record($inspections[0], [['characteristic' => $torque, 'value' => 22]], $this->inspector);
            $this->quality()->record($inspections[1], [['characteristic' => $torque, 'value' => 31]], $this->inspector);
        });

        $this->assertSame('CLOSED', $inspections[0]->fresh()->status, 'A passed in-process check should close itself.');
        $this->assertSame('FAILED', $ops['Assemble']->fresh()->gate_status);
        $this->assertNotEmpty($this->quality()->blocking($order->fresh()));

        // Production is stopped: the next operation and output are refused.
        foreach ([fn () => $floor->startOperation($order, $ops['Pack']->fresh(), $this->engineer),
            fn () => $floor->receiveOutput($order, 1, $this->engineer)] as $attempt) {
            try {
                $this->asCompany($this->company, $attempt);
                $this->fail('Production carried on past a failed, blocking inspection.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('stopped until quality decides', $e->getMessage());
            }
        }

        // Five units scrapped by the quality manager; the gate opens and production carries on.
        $this->asCompany($this->company, fn () => $this->quality()->dispose($inspections[1]->fresh(), 'SCRAP', 'Over-torqued, fasteners yielded', $this->qualityManager));

        $this->assertSame('PASSED', $ops['Assemble']->fresh()->gate_status);
        $this->assertSame([], $this->quality()->blocking($order->fresh()));
        $this->assertEquals(5.0, (float) $order->fresh()->qty_scrapped);
        $this->asCompany($this->company, fn () => $floor->startOperation($order, $ops['Pack']->fresh(), $this->engineer));
        $this->assertSame('IN_PROGRESS', $ops['Pack']->fresh()->status);
    }

    public function test_rework_reopens_the_operation_and_a_fresh_inspection_decides_it_again(): void
    {
        $assemble = $this->asCompany($this->company, fn () => Routing::where('item_id', $this->widget->id)->where('status', 'APPROVED')->first()
            ->operations()->where('name', 'Assemble')->first());
        $this->plan('IN_PROCESS', ['routing_operation_id' => $assemble->id], [['name' => 'Fit', 'kind' => 'ATTRIBUTE']]);
        $order = $this->releasedOrder(4);
        $ops = $order->operations()->get()->keyBy('name');
        $floor = app(ProductionExecution::class);

        $this->asCompany($this->company, function () use ($floor, $order, $ops) {
            $floor->completeOperation($order, $ops['Prepare'], ['completed' => 4], $this->engineer);
            $floor->completeOperation($order, $ops['Assemble']->fresh(), ['completed' => 4], $this->engineer);
            $first = Inspection::where('order_operation_id', $ops['Assemble']->id)->sole();
            $this->quality()->record($first, [['characteristic' => $first->plan->characteristics->first()->uuid, 'passed' => false]], $this->inspector);
            $this->quality()->dispose($first->fresh(), 'REWORK', 'Re-seat the bearings', $this->qualityManager);
        });

        $this->assertSame('READY', $ops['Assemble']->fresh()->status);
        $this->assertNull($ops['Assemble']->fresh()->gate_status);
        $this->assertEquals(4.0, (float) $order->fresh()->qty_reworked);

        $this->asCompany($this->company, function () use ($floor, $order, $ops) {
            $floor->completeOperation($order->fresh(), $ops['Assemble']->fresh(), ['completed' => 4], $this->engineer);
            $second = Inspection::where('order_operation_id', $ops['Assemble']->id)->whereNull('disposition')->sole();
            $this->quality()->record($second, [['characteristic' => $second->plan->characteristics->first()->uuid, 'passed' => true]], $this->inspector);
        });

        $this->assertSame('PASSED', $ops['Assemble']->fresh()->gate_status);
    }

    // ── Final ───────────────────────────────────────────────────────────────────────────────

    public function test_final_inspection_holds_output_and_an_order_cannot_close_with_it_undecided(): void
    {
        $this->plan('FINAL', [], [['name' => 'Finish', 'kind' => 'ATTRIBUTE']]);
        $order = $this->releasedOrder(2);
        $ops = $order->operations()->get()->keyBy('name');
        $floor = app(ProductionExecution::class);
        $life = app(ProductionOrderLifecycle::class);

        $this->asCompany($this->company, function () use ($floor, $order, $ops) {
            foreach ($order->components()->get() as $c) {
                $floor->issue($order, $c, (float) $c->required_qty, $this->engineer);
            }
            $floor->completeOperation($order, $ops['Prepare'], ['completed' => 2], $this->engineer);
            $floor->completeOperation($order, $ops['Assemble']->fresh(), ['completed' => 2], $this->engineer);
            $floor->recordGate($order, $ops['Assemble']->fresh(), 'PASSED', $this->engineer); // no in-process plan: a hand gate
            $floor->completeOperation($order, $ops['Pack']->fresh(), ['completed' => 2], $this->engineer);
            $floor->receiveOutput($order->fresh(), 2, $this->engineer, ['lot_number' => 'W-1']);
        });

        $final = Inspection::withoutGlobalScopes()->where('production_order_id', $order->id)->where('stage', 'FINAL')->sole();
        $this->assertNotNull($final->plan_id);
        $fg = FinStockItem::withoutGlobalScopes()->find($this->widget->stock_item_id);
        $this->assertEquals(0.0, $this->usable($fg), 'Output was usable before its final inspection.');

        // Fails, goes back for rework, is inspected again.
        $this->asCompany($this->company, function () use ($life, $order, $final) {
            $life->sendToQuality($order->fresh(), $this->qualityManager);
            $this->quality()->record($final, [['characteristic' => $final->plan->characteristics->first()->uuid, 'passed' => false]], $this->inspector);
            $this->quality()->dispose($final->fresh(), 'REWORK', 'Paint runs', $this->qualityManager);
        });
        $this->assertSame(PO::IN_PRODUCTION, $order->fresh()->status, 'Rework did not send the order back to production.');
        $again = Inspection::withoutGlobalScopes()->where('production_order_id', $order->id)->where('status', 'OPEN')->sole();
        $this->assertSame($final->lot_id, $again->lot_id);
        $this->assertEquals(0.0, $this->usable($fg), 'Reworked output was released before it was inspected again.');

        $this->asCompany($this->company, function () use ($life, $order, $again) {
            $life->sendToQuality($order->fresh(), $this->qualityManager);
            $this->quality()->record($again, [['characteristic' => $again->plan->characteristics->first()->uuid, 'passed' => true]], $this->inspector);
            $this->quality()->dispose($again->fresh(), 'ACCEPT', null, $this->qualityManager);
            $life->complete($order->fresh(), $this->qualityManager);
            $life->close($order->fresh(), $this->qualityManager);
        });

        $this->assertEquals(2.0, $this->usable($fg));
        $this->assertSame(PO::CLOSED, $order->fresh()->status);
    }

    public function test_closing_is_refused_while_an_inspection_of_the_order_is_undecided(): void
    {
        $order = $this->releasedOrder(1);
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_qc_before_putaway' => false]));
        $this->asCompany($this->company, fn () => $this->quality()->holdForInspection(
            FinStockItem::withoutGlobalScopes()->find($this->bolt->id), $this->store, 1, null, 'FINAL', 'Spot check', $this->inspector)
            ->update(['production_order_id' => $order->id]));
        $order->forceFill(['status' => PO::COMPLETED])->saveQuietly();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Inspections not yet decided');
        $this->asCompany($this->company, fn () => app(ProductionOrderLifecycle::class)->close($order->fresh(), $this->qualityManager));
    }

    // ── Non-conformance ─────────────────────────────────────────────────────────────────────

    public function test_an_ncr_runs_root_cause_to_verified_closure_with_independent_verification(): void
    {
        $ncrs = app(Nonconformances::class);
        $ncr = $this->asCompany($this->company, fn () => $ncrs->raise(['source' => 'INTERNAL', 'severity' => 'MAJOR',
            'description' => 'Weld porosity on bracket', 'defect_code' => 'weld_porosity', 'stock_item_id' => $this->steel->id], $this->company->id, $this->qualityManager));

        $this->assertSame('WELD_POROSITY', $ncr->defect_code);
        $this->assertSame(now()->addDays(7)->toDateString(), $ncr->due_date->toDateString(), 'A major NCR is due in seven days by default.');

        $this->asCompany($this->company, function () use ($ncrs, $ncr) {
            try {
                $ncrs->submitForVerification($ncr);
                $this->fail('An NCR went to verification without a root cause.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('root cause', $e->getMessage());
            }

            $ncrs->update($ncr, ['root_cause' => 'Contaminated shielding gas', 'containment' => 'Quarantined batch']);
            $this->assertSame('INVESTIGATING', $ncr->status);

            $fix = $ncrs->addAction($ncr, ['type' => 'CORRECTIVE', 'description' => 'Replace gas supply', 'owner_id' => $this->qualityManager->id]);
            $ncrs->addAction($ncr, ['type' => 'PREVENTIVE', 'description' => 'Gas purity check at each cylinder change']);
            $this->assertSame('ACTION', $ncr->fresh()->status);

            try {
                $ncrs->submitForVerification($ncr->fresh());
                $this->fail('An NCR went to verification with actions open.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Every action', $e->getMessage());
            }

            foreach ($ncr->actions()->get() as $a) {
                $ncrs->completeAction($a, 'Done', $this->qualityManager);
            }
            $ncrs->submitForVerification($ncr->fresh());

            // The person who did the actions cannot verify a major NCR.
            try {
                $ncrs->verify($ncr->fresh(), 'EFFECTIVE', null, $this->qualityManager);
                $this->fail('A major NCR was verified by the person who fixed it.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Somebody else', $e->getMessage());
            }

            // Not effective sends it back; effective closes it.
            $ncrs->verify($ncr->fresh(), 'NOT_EFFECTIVE', 'Porosity recurred', $this->approver);
            $this->assertSame('ACTION', $ncr->fresh()->status);
            $ncrs->submitForVerification($ncr->fresh());
            $ncrs->verify($ncr->fresh(), 'EFFECTIVE', 'Three weeks clean', $this->approver);
        });

        $this->assertSame('CLOSED', $ncr->fresh()->status);
        $this->assertSame('EFFECTIVE', $ncr->fresh()->effectiveness);
    }

    public function test_repeat_defects_are_counted_on_the_code(): void
    {
        $ncrs = app(Nonconformances::class);
        $raise = fn (string $code, ?int $item) => $this->asCompany($this->company, fn () => $ncrs->raise(['description' => 'x', 'defect_code' => $code,
            'stock_item_id' => $item], $this->company->id, $this->inspector));

        $first = $raise('DENT', $this->steel->id);
        $raise('DENT', $this->steel->id);
        $raise('DENT', $this->bolt->id);   // another material
        $raise('SCRATCH', $this->steel->id); // another defect

        $this->assertCount(1, $this->asCompany($this->company, fn () => $ncrs->repeats($first)));
    }

    // ── Screens ─────────────────────────────────────────────────────────────────────────────

    public function test_the_quality_screens_render_and_each_role_sees_only_its_own_actions(): void
    {
        // The quality hub also holds reports and setup; this is about its quality cards.
        $q = fn ($p) => str_starts_with($p, '/manufacturing/quality/');
        $cards = array_values(array_filter(array_column($this->actingAs($this->inspector)->get('/manufacturing/quality')->assertOk()->viewData('page')['props']['cards'], 'path'), $q));
        $this->assertEqualsCanonicalizing(['/manufacturing/quality/inspections', '/manufacturing/quality/nonconformances', '/manufacturing/quality/plans'], $cards);
        $this->assertSame([], array_values(array_filter(array_column($this->actingAs($this->engineer)->get('/manufacturing/quality')->viewData('page')['props']['cards'], 'path'), $q)));

        // The quality manager defines a plan and its check through the screens.
        $this->actingAs($this->qualityManager)->post(route('manufacturing.quality.plans.store'), [
            'code' => 'iqc-steel', 'name' => 'Steel receipt', 'stage' => 'INCOMING', 'stock_item_id' => $this->steel->id,
            'supplier_id' => $this->supplierId, 'sample_size' => 1, 'blocks_production' => true, 'requires_certificate' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $plan = $this->asCompany($this->company, fn () => InspectionPlan::where('code', 'IQC-STEEL')->sole());
        $this->actingAs($this->qualityManager)->post(route('manufacturing.quality.plans.characteristics.store', $plan->uuid), [
            'name' => 'Thickness', 'kind' => 'MEASURED', 'uom' => 'mm', 'lower_limit' => 4.9, 'upper_limit' => 5.1,
        ])->assertSessionHas('success');
        $this->actingAs($this->inspector)->post(route('manufacturing.quality.plans.store'), ['code' => 'X'])->assertForbidden();
        $this->actingAs($this->inspector)->get(route('manufacturing.quality.plans.show', $plan->uuid))->assertOk();

        // The inspector receives and records; cannot decide.
        $this->actingAs($this->inspector)->post(route('manufacturing.quality.inspections.receive'), [
            'stock_item_id' => $this->steel->id, 'warehouse_id' => $this->store->id, 'qty' => 20, 'unit_cost' => 10,
            'lot_number' => 'HEAT-9', 'supplier_id' => $this->supplierId, 'purchase_order_id' => $this->poId,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $inspection = $this->asCompany($this->company, fn () => Inspection::latest('id')->firstOrFail());
        $this->assertSame($plan->id, $inspection->plan_id);

        $page = $this->actingAs($this->inspector)->get(route('manufacturing.quality.inspections.show', $inspection->uuid))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Quality/InspectionShow', $page['component']);
        $this->assertSame(['Thickness'], array_column($page['props']['inspection']['checks'], 'name'));
        $this->assertTrue($page['props']['can']['record']);
        $this->assertFalse($page['props']['can']['dispose']);

        $check = $page['props']['inspection']['checks'][0]['id'];
        $this->actingAs($this->inspector)->post(route('manufacturing.quality.inspections.record', $inspection->uuid), [
            'results' => [['characteristic' => $check, 'value' => 5.02]],
        ])->assertSessionHas('success');
        $this->actingAs($this->inspector)->post(route('manufacturing.quality.inspections.dispose', $inspection->uuid), ['decision' => 'ACCEPT'])->assertForbidden();

        $page = $this->actingAs($this->qualityManager)->get(route('manufacturing.quality.inspections.show', $inspection->uuid))->viewData('page');
        $this->assertContains('ACCEPT', $page['props']['dispositions']);
        $this->actingAs($this->qualityManager)->post(route('manufacturing.quality.inspections.dispose', $inspection->uuid), ['decision' => 'ACCEPT'])
            ->assertSessionHas('success');
        $this->assertEquals(20.0, $this->usable($this->steel));

        // The queue and the NCR register.
        $queue = $this->actingAs($this->inspector)->get(route('manufacturing.quality.inspections.index').'?status=CLOSED')->viewData('page');
        $this->assertSame('Modules/Manufacturing/Quality/Inspections', $queue['component']);
        $this->assertCount(1, $queue['props']['inspections']['data']);
        $this->assertNotNull($queue['props']['options'], 'The inspector was not given the receive form\'s choices.');

        $this->actingAs($this->inspector)->post(route('manufacturing.quality.ncrs.store'), [
            'source' => 'CUSTOMER', 'severity' => 'MINOR', 'description' => 'Customer reports burrs', 'defect_code' => 'BURR',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $ncr = $this->asCompany($this->company, fn () => Nonconformance::latest('id')->firstOrFail());

        $page = $this->actingAs($this->inspector)->get(route('manufacturing.quality.ncrs.show', $ncr->uuid))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Quality/NonconformanceShow', $page['component']);
        $this->assertFalse($page['props']['can']['update']);
        $this->actingAs($this->inspector)->patch(route('manufacturing.quality.ncrs.update', $ncr->uuid), ['root_cause' => 'x'])->assertForbidden();

        $this->actingAs($this->qualityManager)->patch(route('manufacturing.quality.ncrs.update', $ncr->uuid), ['root_cause' => 'Worn deburring tool'])->assertSessionHas('success');
        $this->actingAs($this->qualityManager)->post(route('manufacturing.quality.ncrs.actions.store', $ncr->uuid), [
            'type' => 'CORRECTIVE', 'description' => 'Replace tool', 'owner_id' => $this->inspector->id,
        ])->assertSessionHas('success');
        $action = $ncr->actions()->first();

        // The action's owner may mark their own action done.
        $this->actingAs($this->inspector)->post(route('manufacturing.quality.ncrs.actions.complete', ['uuid' => $ncr->uuid, 'actionUuid' => $action->uuid]))
            ->assertSessionHas('success');
        $this->actingAs($this->qualityManager)->post(route('manufacturing.quality.ncrs.submit', $ncr->uuid))->assertSessionHas('success');
        $this->actingAs($this->qualityManager)->post(route('manufacturing.quality.ncrs.verify', $ncr->uuid), ['effectiveness' => 'EFFECTIVE'])->assertSessionHas('success');
        $this->assertSame('CLOSED', $ncr->fresh()->status);

        $list = $this->actingAs($this->inspector)->get(route('manufacturing.quality.ncrs.index').'?status=CLOSED')->viewData('page');
        $this->assertSame([$ncr->number], array_column($list['props']['ncrs']['data'], 'number'));
    }

    public function test_another_tenants_supplier_or_material_cannot_be_named(): void
    {
        $rival = \App\Modules\Tenancy\Models\Company::create(['name' => 'Rival Mills']);
        $theirSupplier = (int) DB::table('suppliers')->insertGetId(['company_name' => 'Theirs', 'company_id' => $rival->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->inspector)->post(route('manufacturing.quality.inspections.receive'), [
            'stock_item_id' => $this->steel->id, 'warehouse_id' => $this->store->id, 'qty' => 1, 'unit_cost' => 1,
            'lot_number' => 'L', 'supplier_id' => $theirSupplier,
        ])->assertSessionHasErrors('supplier_id');
    }
}
