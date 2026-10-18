<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\ApprovalLimit;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * MFG-006 -- production orders, end to end on real stock and real journals.
 *
 * The product: a WIDGET is 2 kg of STEEL and 4 BOLTs, made by Prepare -> Assemble (an inspection
 * point) -> Pack. STEEL costs 10/kg and BOLT 2 each, so the standard material cost of one widget is
 * 2 x 10 + 4 x 2 = 28.
 */
class ProductionOrderTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private FinStockItem $steel;

    private FinStockItem $bolt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');

        $this->asCompany($this->company, function () {
            Shift::create([
                'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
                'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true,
            ]);
        });

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
    }

    private function orders(): ProductionOrders { return app(ProductionOrders::class); }

    private function lifecycle(): ProductionOrderLifecycle { return app(ProductionOrderLifecycle::class); }

    private function floor(): ProductionExecution { return app(ProductionExecution::class); }

    private function stockUp(float $steel = 100, float $bolts = 200): void
    {
        $this->asCompany($this->company, function () use ($steel, $bolts) {
            $ledger = app(StockLedger::class);
            $ledger->receive($this->steel->fresh(), $steel, 10, $this->store, ['reference' => 'GRN-1']);
            $ledger->receive($this->bolt->fresh(), $bolts, 2, $this->store, ['reference' => 'GRN-1']);
        });
    }

    private function raise(float $qty = 10, array $extra = []): PO
    {
        return $this->asCompany($this->company, fn () => $this->orders()->create(array_merge([
            'item' => $this->widget, 'quantity' => $qty, 'due_date' => now()->addDays(10)->toDateString(),
            'factory' => $this->plant, 'warehouse' => $this->store,
        ], $extra), $this->engineer));
    }

    /** Run an order up to Released, with stock on hand. */
    private function released(float $qty = 10): PO
    {
        $this->stockUp();
        $order = $this->raise($qty);
        $this->asCompany($this->company, function () use ($order) {
            $this->lifecycle()->plan($order, $this->engineer);
            $this->lifecycle()->approve($order, $this->approver);
            $this->lifecycle()->release($order, $this->approver);
        });

        return $order->fresh();
    }

    // ── Creation ────────────────────────────────────────────────────────────────────────────

    public function test_an_order_is_numbered_pinned_and_copied_from_its_recipe(): void
    {
        $order = $this->raise(10);

        $this->assertSame('PO-'.now()->format('Y').'-00001', $order->number);
        $this->assertSame(PO::DRAFT, $order->status);
        $this->assertNotNull($order->bom_id);
        $this->assertNotNull($order->routing_id);

        $components = $order->components()->with('stockItem')->get()->mapWithKeys(fn ($c) => [$c->stockItem->code => (float) $c->required_qty]);
        $this->assertEquals(['STEEL' => 20.0, 'BOLT' => 40.0], $components->all());

        $this->assertSame(['Prepare', 'Assemble', 'Pack'], $order->operations()->pluck('name')->all());

        // Material 20 x 10 + 40 x 2 = 280. Machine and labour from the centre's rates.
        $this->assertEquals(280.0, (float) $order->est_material_cost);
        $this->assertGreaterThan(0, (float) $order->est_machine_cost);
        $this->assertGreaterThan(0, (float) $order->est_labour_cost);
        $this->assertSame('CREATED', $order->events()->first()->action);
    }

    public function test_an_order_cannot_be_raised_without_an_approved_recipe_or_required_routing(): void
    {
        $bare = $this->product('NO-RECIPE');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no approved bill of materials');

        $this->asCompany($this->company, fn () => $this->orders()->create([
            'item' => $bare, 'quantity' => 1, 'due_date' => now()->toDateString(),
            'factory' => $this->plant, 'warehouse' => $this->store,
        ], $this->engineer));
    }

    public function test_an_engineering_change_after_release_does_not_reach_the_order(): void
    {
        $order = $this->raise(10);
        $pinned = $order->bom_id;

        // Engineering revises the recipe to 3 kg of steel and approves it.
        $this->asCompany($this->company, function () {
            $versioning = app(\App\Modules\Operations\Manufacturing\Services\BomVersioning::class);
            $current = \App\Modules\Operations\Manufacturing\Models\Bom::where('item_id', $this->widget->id)->where('status', 'APPROVED')->first();
            $draft = $versioning->revise($current, $this->engineer->id);
            $draft->lines()->where('component_stock_item_id', $this->steel->id)->update(['quantity_per' => 3]);
            $versioning->approve($draft->fresh(), $this->approver->id);
        });

        $this->assertSame($pinned, $order->fresh()->bom_id);
        $this->assertEquals(20.0, (float) $order->components()->where('stock_item_id', $this->steel->id)->value('required_qty'));
    }

    // ── Approval ────────────────────────────────────────────────────────────────────────────

    public function test_approval_is_refused_when_material_is_short_and_an_override_is_audited(): void
    {
        $order = $this->raise(10);  // no stock at all
        $this->asCompany($this->company, fn () => $this->lifecycle()->plan($order, $this->engineer));

        try {
            $this->asCompany($this->company, fn () => $this->lifecycle()->approve($order, $this->approver));
            $this->fail('An order short of material was approved.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('material is short', $e->getMessage());
        }

        // The approver holds no override: a reason alone is not enough.
        try {
            $this->asCompany($this->company, fn () => $this->lifecycle()->approve($order, $this->approver, 'Steel arrives Monday'));
            $this->fail('An override was accepted from someone without the permission.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('authorised override', $e->getMessage());
        }

        $this->approver->givePermissionTo(RoleCatalog::OVERRIDE_CONTROLS);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->asCompany($this->company, fn () => $this->lifecycle()->approve($order, $this->approver->fresh(), 'Steel arrives Monday'));

        $event = $order->fresh()->events()->where('action', 'APPROVED')->first();
        $this->assertSame(PO::APPROVED, $order->fresh()->status);
        $this->assertTrue((bool) $event->is_exception);
        $this->assertSame('Steel arrives Monday', $event->reason);
    }

    public function test_an_order_above_an_approval_band_needs_the_bands_permission(): void
    {
        $this->stockUp();
        $order = $this->raise(10);
        $this->asCompany($this->company, fn () => $this->lifecycle()->plan($order, $this->engineer));

        $this->asCompany($this->company, fn () => ApprovalLimit::create([
            'company_id' => $this->company->id, 'document_type' => 'PRODUCTION_ORDER', 'min_amount' => 0,
            'max_amount' => null, 'required_permission' => RoleCatalog::CONFIGURE, 'is_active' => true,
        ]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(RoleCatalog::CONFIGURE);
        $this->asCompany($this->company, fn () => $this->lifecycle()->approve($order, $this->approver));
    }

    public function test_a_transition_from_the_wrong_state_is_refused(): void
    {
        $order = $this->raise();

        $this->expectException(DomainException::class);
        $this->asCompany($this->company, fn () => $this->lifecycle()->release($order, $this->approver));
    }

    // ── Release and execution ───────────────────────────────────────────────────────────────

    public function test_releasing_reserves_the_material_and_opens_the_first_operation(): void
    {
        $order = $this->released(10);

        $held = FinStockReservation::withoutGlobalScopes()->where('source_type', PO::SOURCE_TYPE)->where('source_id', $order->id)
            ->get()->mapWithKeys(fn ($r) => [$r->item_id => $r->remaining()]);

        $this->assertEquals([$this->steel->id => 20.0, $this->bolt->id => 40.0], $held->all());
        $this->assertSame(['READY', 'PENDING', 'PENDING'], $order->operations()->pluck('status')->all());

        // Another order cannot now take what this one holds: 100 kg - 20 held = 80 free.
        $this->assertSame(80.0, app(StockLedger::class)->available($this->steel->fresh(), $this->store->id));
    }

    public function test_the_routing_engine_refuses_an_operation_out_of_order_and_audits_an_override(): void
    {
        $order = $this->released(10);
        $ops = $order->operations()->get()->keyBy('name');

        try {
            $this->asCompany($this->company, fn () => $this->floor()->completeOperation($order, $ops['Assemble'], ['completed' => 10], $this->engineer));
            $this->fail('Assemble was completed before Prepare.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('10 Prepare', $e->getMessage());
        }

        $this->approver->givePermissionTo(RoleCatalog::OVERRIDE_CONTROLS);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->asCompany($this->company, fn () => $this->floor()->completeOperation($order, $ops['Assemble'], ['completed' => 10],
            $this->approver->fresh(), 'Prepare done on paper during the outage'));

        $event = $order->events()->where('action', 'OUT_OF_SEQUENCE')->firstOrFail();
        $this->assertTrue((bool) $event->is_exception);
    }

    public function test_an_inspection_gate_must_pass_before_the_next_operation_can_complete(): void
    {
        $order = $this->released(10);
        $ops = $order->operations()->get()->keyBy('name');

        $this->asCompany($this->company, function () use ($order, $ops) {
            $this->floor()->completeOperation($order, $ops['Prepare'], ['completed' => 10], $this->engineer);
            $this->floor()->completeOperation($order, $ops['Assemble']->fresh(), ['completed' => 10], $this->engineer);
        });

        try {
            $this->asCompany($this->company, fn () => $this->floor()->completeOperation($order, $ops['Pack']->fresh(), ['completed' => 10], $this->engineer));
            $this->fail('Pack completed while the inspection gate was unrecorded.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('gate', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $this->floor()->recordGate($order, $ops['Assemble']->fresh(), 'PASSED', $this->engineer));
        $this->asCompany($this->company, fn () => $this->floor()->completeOperation($order, $ops['Pack']->fresh(), ['completed' => 10], $this->engineer));

        $this->assertSame('COMPLETED', $ops['Pack']->fresh()->status);
    }

    public function test_material_is_issued_from_the_orders_reservation_and_a_retry_counts_once(): void
    {
        $order = $this->released(10);
        $steel = $order->components()->where('stock_item_id', $this->steel->id)->first();

        $this->asCompany($this->company, function () use ($order, $steel) {
            $this->floor()->issue($order, $steel, 12, $this->engineer, ['idempotency_key' => 'scan-1']);
            $this->floor()->issue($order, $steel->fresh(), 12, $this->engineer, ['idempotency_key' => 'scan-1']);
        });

        $this->assertEquals(12.0, (float) $steel->fresh()->issued_qty, 'A retried scan issued the material twice.');
        $this->assertEquals(120.0, (float) $order->fresh()->act_material_cost);
        $this->assertSame(8.0, FinStockReservation::withoutGlobalScopes()
            ->where('source_id', $order->id)->where('item_id', $this->steel->id)->first()->remaining());
    }

    public function test_over_issue_is_refused_unless_the_factory_allows_it(): void
    {
        $order = $this->released(10);
        $steel = $order->components()->where('stock_item_id', $this->steel->id)->first();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('more than the order needs');
        $this->asCompany($this->company, fn () => $this->floor()->issue($order, $steel, 25, $this->engineer));
    }

    public function test_nothing_can_be_recorded_against_an_order_on_hold(): void
    {
        $order = $this->released(10);
        $this->asCompany($this->company, fn () => $this->lifecycle()->hold($order, $this->approver, 'Machine guard missing'));

        try {
            $this->asCompany($this->company, fn () => $this->floor()->issue($order->fresh(),
                $order->components()->first(), 1, $this->engineer));
            $this->fail('Material was issued to an order on hold.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('on hold', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $this->lifecycle()->resume($order->fresh(), $this->approver));
        $this->assertSame(PO::RELEASED, $order->fresh()->status, 'Resume did not return the order to where it was.');
    }

    public function test_cancelling_releases_reservations_but_not_while_material_is_with_the_order(): void
    {
        $order = $this->released(10);
        $steel = $order->components()->where('stock_item_id', $this->steel->id)->first();
        $this->asCompany($this->company, fn () => $this->floor()->issue($order, $steel, 5, $this->engineer));

        try {
            $this->asCompany($this->company, fn () => $this->lifecycle()->cancel($order->fresh(), $this->approver, 'Customer withdrew'));
            $this->fail('An order holding issued material was cancelled.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('return it', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $this->floor()->returnMaterial($order->fresh(), $steel->fresh(), 5, $this->engineer));
        $this->asCompany($this->company, fn () => $this->lifecycle()->cancel($order->fresh(), $this->approver, 'Customer withdrew'));

        $this->assertSame(PO::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, FinStockReservation::withoutGlobalScopes()->where('source_id', $order->id)->where('status', 'OPEN')->count());
        $this->assertSame(100.0, app(StockLedger::class)->available($this->steel->fresh(), $this->store->id));
    }

    // ── The whole lifecycle ─────────────────────────────────────────────────────────────────

    public function test_the_full_lifecycle_from_draft_to_closed_settles_work_in_progress(): void
    {
        $order = $this->released(10);
        $ops = $order->operations()->get()->keyBy('name');
        $steel = $order->components()->where('stock_item_id', $this->steel->id)->first();
        $bolt = $order->components()->where('stock_item_id', $this->bolt->id)->first();

        $this->asCompany($this->company, function () use ($order, $ops, $steel, $bolt) {
            // 22 kg of steel used instead of 20: two kilograms of waste.
            $this->floor()->issue($order, $steel, 20, $this->engineer);
            $this->floor()->issue($order, $bolt, 40, $this->engineer);
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['allow_over_issue' => true]);
            $this->floor()->issue($order->fresh(), $steel->fresh(), 2, $this->engineer);

            $this->floor()->completeOperation($order, $ops['Prepare'], ['completed' => 10, 'machine_minutes' => 20, 'labour_minutes' => 20], $this->engineer);
            $this->floor()->completeOperation($order, $ops['Assemble']->fresh(), ['completed' => 10, 'machine_minutes' => 45, 'labour_minutes' => 45], $this->engineer);
            $this->floor()->recordGate($order, $ops['Assemble']->fresh(), 'PASSED', $this->engineer);
            $this->floor()->completeOperation($order, $ops['Pack']->fresh(), ['completed' => 10, 'machine_minutes' => 15, 'labour_minutes' => 15], $this->engineer);

            $this->floor()->receiveOutput($order->fresh(), 10, $this->engineer, ['lot_number' => 'WID-001']);
        });

        $order->refresh();
        $this->assertSame(PO::IN_PRODUCTION, $order->status, 'Starting an operation did not start the order.');
        $this->assertNotNull($order->started_by);
        $this->assertEquals(10.0, (float) $order->qty_completed);

        // Output went to quarantine: the factory requires inspection before putaway.
        $fg = FinStockItem::withoutGlobalScopes()->find($this->widget->stock_item_id);
        $this->assertSame(0.0, app(StockLedger::class)->available($fg, $this->store->id));

        $this->asCompany($this->company, fn () => $this->lifecycle()->sendToQuality($order, $this->approver));

        try {
            $this->asCompany($this->company, fn () => $this->lifecycle()->complete($order->fresh(), $this->approver));
            $this->fail('An order was completed with its output still in quarantine.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('quarantine', $e->getMessage());
        }

        // The quarantined output opened its final inspection; passing and accepting it releases the lot.
        $this->asCompany($this->company, function () use ($order) {
            $quality = app(\App\Modules\Operations\Manufacturing\Services\QualityControl::class);
            $inspection = \App\Modules\Operations\Manufacturing\Models\Inspection::where('production_order_id', $order->id)->sole();
            $this->assertSame('FINAL', $inspection->stage);
            $quality->record($inspection, [['name' => 'Visual', 'passed' => true]], $this->approver);
            $quality->dispose($inspection->fresh(), 'ACCEPT', null, $this->approver);
        });

        $this->asCompany($this->company, fn () => $this->lifecycle()->complete($order->fresh(), $this->approver));

        $recon = $order->fresh()->events()->where('action', 'COMPLETED')->first()->detail;
        $steelLine = collect($recon['materials'])->firstWhere('item', 'STEEL');
        $this->assertEquals(2.0, $steelLine['variance'], 'The reconciliation missed the two kilograms of waste.');

        $this->asCompany($this->company, fn () => $this->lifecycle()->close($order->fresh(), $this->approver));
        $order->refresh();

        // WIP: issued 22 x 10 + 40 x 2 = 300; output received at standard 10 x 28 = 280; variance 20.
        $this->assertSame(PO::CLOSED, $order->status);
        $this->assertNotNull($order->close_journal_id);
        $variance = FinJournalLine::where('journal_id', $order->close_journal_id)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', '5600')->value('fin_journal_lines.debit');
        $this->assertEquals(20.0, (float) $variance);
        // The movements still show what went in and out; the variance journal is what settled it.
        $this->assertEquals(20.0, $this->asCompany($this->company, fn () => $this->lifecycle()->wipBalance($order)));

        // A closed order is a record: the database refuses an edit.
        try {
            DB::transaction(fn () => DB::table('mfg_production_orders')->where('id', $order->id)->update(['qty_completed' => 99]));
            $this->fail('A closed production order was edited.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('is closed', $e->getMessage());
        }

        $this->assertSame(
            ['CREATED', 'PLANNED', 'APPROVED', 'RELEASED', 'STARTED', 'GATE_PASSED', 'OUTPUT_RECEIVED', 'SENT_TO_QUALITY', 'COMPLETED', 'CLOSED'],
            $order->events()->reorder('id')->pluck('action')->all()
        );
    }

    public function test_a_closed_orders_work_in_progress_is_zero_in_the_ledger(): void
    {
        $order = $this->released(10);
        $steel = $order->components()->where('stock_item_id', $this->steel->id)->first();
        $bolt = $order->components()->where('stock_item_id', $this->bolt->id)->first();

        $this->asCompany($this->company, function () use ($order, $steel, $bolt) {
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_qc_before_putaway' => false]);
            $this->floor()->issue($order, $steel, 20, $this->engineer);
            $this->floor()->issue($order, $bolt, 40, $this->engineer);
            foreach ($order->operations()->get() as $op) {
                $this->floor()->completeOperation($order, $op->fresh(), ['completed' => 9], $this->engineer);
                if ($op->is_inspection_point) {
                    $this->floor()->recordGate($order, $op->fresh(), 'PASSED', $this->engineer);
                }
            }
            $this->floor()->receiveOutput($order->fresh(), 9, $this->engineer);
            $this->floor()->recordLoss($order->fresh(), 1, 0, $this->engineer, 'Dropped');
            $this->lifecycle()->sendToQuality($order->fresh(), $this->approver);
            $this->lifecycle()->complete($order->fresh(), $this->approver);
            $this->lifecycle()->close($order->fresh(), $this->approver);
        });

        // Issued 280; received 9 x 28 = 252; the lost unit's 28 is the variance.
        $wip = FinJournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_accounts.code', '1310')->where('fin_accounts.company_id', $this->company->id)
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit - fin_journal_lines.credit), 0) AS bal')->value('bal');

        $this->assertEqualsWithDelta(0.0, (float) $wip, 0.01, 'Work in progress was not cleared when the order closed.');
        $this->assertEquals(1.0, (float) $order->fresh()->qty_scrapped);
    }

    public function test_an_order_at_another_factory_is_invisible_to_someone_assigned_elsewhere(): void
    {
        $order = $this->raise();
        $other = $this->factory('OTHER');

        \App\Modules\Operations\Manufacturing\Models\FactoryAssignment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'factory_id' => $other->id, 'user_id' => $this->planner->id,
        ]);
        app(\App\Modules\Operations\Manufacturing\Services\FactoryAccess::class)->flush();

        $this->actingAs($this->planner);
        $this->assertNull(PO::query()->find($order->id));
    }
}
