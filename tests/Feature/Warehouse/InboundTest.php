<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\WarehouseManagement\Models\DockAppointment;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Models\ReceiptDiscrepancy;
use App\Modules\Operations\WarehouseManagement\Models\SupplierReturn;
use App\Modules\Operations\WarehouseManagement\Services\DockAppointments;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\PutawayTasks;
use App\Modules\Operations\WarehouseManagement\Services\Receiving;
use App\Modules\Operations\WarehouseManagement\Services\SupplierReturns;
use App\Modules\Operations\WarehouseManagement\Services\SupplierScorecard;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WMS-030 to WMS-035 (and the put-away WMS-032 starts): receiving on Procurement's GRNs.
 *
 * The decisive assertions read Procurement's received quantities, Finance's journal lines and
 * Manufacturing's inspections, because receiving is only integrated if all three moved together.
 */
class InboundTest extends WarehouseTestCase
{
    private FinWarehouse $store;

    private FinStockBin $dock;

    private FinStockBin $shelf;

    private FinStockItem $item;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->store = $this->warehouse('DC', ['is_default' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $loc = app(LocationService::class);
            $this->dock = $loc->create($this->store, ['code' => 'DOCK-1', 'kind' => 'RECEIVING']);
            $this->shelf = $loc->create($this->store, ['code' => 'A-01', 'max_units' => 1000, 'pick_sequence' => 10]);
            $loc->create($this->store, ['code' => 'RET-1', 'kind' => 'RETURNS']);
            $loc->create($this->store, ['code' => 'QC-1', 'kind' => 'QUARANTINE']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'OIL-2L', 'name' => 'Cooking oil 2L', 'uom' => 'ea']);
            app(ItemCatalogue::class)->savePackaging($this->item, ['level' => 'CASE', 'uom_id' => $this->uom('case')->id, 'qty_in_base' => 6]);
        });
        $this->supplier = (int) DB::table('suppliers')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->company->id,
            'company_name' => 'Golden Oils', 'created_at' => now(), 'updated_at' => now()]);
        $this->manager->givePermissionTo(RoleCatalog::all());
    }

    private function uom(string $code): Uom
    {
        return Uom::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    /** An approved order for $cases cases of oil at 120 a case, delivered to DC. */
    private function order(float $cases = 10, array $attrs = [], bool $linked = true): PurchaseOrder
    {
        return $this->asCompany($this->company, function () use ($cases, $attrs, $linked) {
            $po = PurchaseOrder::create($attrs + [
                'supplier_id' => $this->supplier, 'title' => 'Oil restock', 'amount' => $cases * 120, 'status' => 'approved',
                'order_date' => now()->subDays(5)->toDateString(), 'expected_delivery' => now()->toDateString(),
                'warehouse_id' => $this->store->id, 'created_by' => $this->manager->id,
            ]);
            PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'name' => 'Oil 2L x6', 'quantity' => $cases, 'unit_price' => 120,
                'stock_item_id' => $linked ? $this->item->id : null, 'uom_id' => $linked ? $this->uom('case')->id : null]);

            return $po->fresh('items');
        });
    }

    private function receive(PurchaseOrder $po, array $line, array $header = [], $user = null): GoodsReceivedNote
    {
        $user ??= $this->manager;
        $this->actingAs($user);

        return $this->asCompany($this->company, fn () => app(Receiving::class)->receiveOrder($po, [['item_id' => $po->items[0]->id] + $line], $header, $user));
    }

    // ── WMS-030 expected receipts ────────────────────────────────────────────────────────────

    public function test_open_order_lines_are_expected_and_late_ones_are_overdue(): void
    {
        $this->order(10, ['expected_delivery' => now()->subDays(3)->toDateString()]);
        $this->order(4, ['po_number' => 'PO-DRAFT', 'status' => 'draft']);

        $rows = $this->asCompany($this->company, fn () => app(Receiving::class)->expected($this->store->id));

        $this->assertCount(1, $rows, 'A draft order was treated as expected.');
        $this->assertTrue($rows[0]['overdue']);
        $this->assertEquals(10, $rows[0]['outstanding']);
    }

    // ── WMS-032 receiving posts through Procurement's GRN ────────────────────────────────────

    public function test_receiving_updates_procurement_posts_stock_to_the_dock_and_accrues_grni(): void
    {
        $po = $this->order(10);
        $grn = $this->receive($po, ['accepted_qty' => 4], ['delivery_note_ref' => 'DN-881', 'vehicle_reg' => 'CA 123-456']);

        $this->assertMatchesRegularExpression('/^GRN\d{4}\d{5}$/', $grn->grn_number);
        $this->assertEquals(4, $po->items[0]->fresh()->received_qty, 'Procurement\'s received quantity did not move.');
        $this->assertSame('partial', $po->fresh()->received_status);

        // 4 cases of 6 = 24 units, at 120/6 = 20 each, in the receiving dock.
        $pos = FinStockPosition::withoutGlobalScopes()->where('item_id', $this->item->id)->where('bin_id', $this->dock->id)->first();
        $this->assertEquals(24, $pos->qty);
        $m = FinStockMovement::withoutGlobalScopes()->where('source_type', 'GRN')->where('source_id', $grn->id)->firstOrFail();
        $this->assertEquals(20, (float) $m->unit_cost);
        $this->assertEquals(4, (float) $m->txn_qty);
        $this->assertSame($this->uom('case')->id, $m->txn_uom_id);

        $credit = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_id', $m->journal_id)->where('l.credit', '>', 0)->value('a.code');
        $this->assertSame('2150', $credit, 'A receipt credited something other than Goods Received Not Invoiced.');

        // Put-away is initiated, pointing at the shelf.
        $task = PutawayTask::withoutGlobalScopes()->where('source_type', 'GRN')->where('source_id', $grn->id)->firstOrFail();
        $this->assertEquals(24, $task->qty);
        $this->assertSame($this->shelf->id, $task->suggested_bin_id);
    }

    public function test_procurements_own_receiving_screen_posts_stock_too(): void
    {
        $po = $this->order(2);
        $unlinked = $this->order(3, [], false);

        $this->asCompany($this->company, function () use ($po, $unlinked) {
            app(PurchaseOrderService::class)->createGRN($po, [['item_id' => $po->items[0]->id, 'accepted_qty' => 2]], $this->manager->id);
            app(PurchaseOrderService::class)->createGRN($unlinked, [['item_id' => $unlinked->items[0]->id, 'accepted_qty' => 3]], $this->manager->id);
        });

        $this->assertEquals(12, $this->item->fresh()->qty_on_hand, 'A Procurement GRN of a stock line did not post stock.');
        $this->assertSame(1, DB::table('procurement_inventory_links')->where('purchase_order_id', $unlinked->id)->count(),
            'A line with no stock item lost its old inventory link.');
        $this->assertSame(0, DB::table('procurement_inventory_links')->where('purchase_order_id', $po->id)->count(),
            'A stock line got a name-only inventory link beside its real stock.');
    }

    public function test_a_lot_tracked_item_needs_its_batch_and_a_serial_tracked_one_a_serial_per_unit(): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item, ['is_lot_tracked' => true]));
        $po = $this->order(2);

        try {
            $this->receive($po, ['accepted_qty' => 1]);
            $this->fail('A lot-tracked item was received without a batch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('batch or lot number', $e->getMessage());
        }
        $this->assertEquals(0, $po->items[0]->fresh()->received_qty, 'A refused receipt still moved Procurement\'s quantity.');

        $grn = $this->receive($po, ['accepted_qty' => 1, 'lot_number' => 'B-2026-07', 'expires_on' => now()->addYear()->toDateString()]);
        $this->assertSame('B-2026-07', DB::table('fin_stock_lots')->where('id', $grn->items[0]->fresh()->lot_id)->value('lot_number'));
    }

    public function test_short_shelf_life_goes_to_quarantine_with_a_discrepancy(): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item, ['min_remaining_shelf_life_days' => 90]));
        $po = $this->order(1);

        $grn = $this->receive($po, ['accepted_qty' => 1, 'expires_on' => now()->addDays(20)->toDateString()]);

        $this->assertSame('QUARANTINE', $grn->items[0]->fresh()->stock_status);
        $this->assertTrue(ReceiptDiscrepancy::withoutGlobalScopes()->where('grn_id', $grn->id)->where('kind', 'SHORT_SHELF_LIFE')->where('status', 'OPEN')->exists());
    }

    public function test_an_item_needing_inspection_is_held_and_released_by_manufacturings_inspection(): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item, ['requires_inspection' => true]));
        $po = $this->order(1);
        $grn = $this->receive($po, ['accepted_qty' => 1]);

        $this->assertSame('PENDING', $grn->fresh()->inspection_status);
        $this->assertEquals(6, (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $this->item->id)->where('status', 'QUARANTINE')->sum('qty'));
        $inspection = Inspection::withoutGlobalScopes()->where('receipt_reference', $grn->grn_number)->firstOrFail();
        $this->assertSame(0, PutawayTask::withoutGlobalScopes()->where('source_id', $grn->id)->count(), 'Held stock was given a put-away task before inspection.');

        $this->actingAs($this->manager);
        $this->asCompany($this->company, function () use ($inspection) {
            app(QualityControl::class)->record($inspection->fresh(), [['name' => 'Seal intact', 'passed' => true]], $this->manager);
            app(QualityControl::class)->dispose($inspection->fresh(), Inspection::ACCEPT, 'Spec OK', $this->manager);
        });

        $this->assertSame('PASSED', $grn->fresh()->inspection_status);
        $this->assertEquals(6, (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $this->item->id)->where('status', 'AVAILABLE')->sum('qty'));
        $this->assertSame(1, PutawayTask::withoutGlobalScopes()->where('source_type', 'GRN')->where('source_id', $grn->id)->count());
    }

    // ── WMS-033 discrepancies ────────────────────────────────────────────────────────────────

    public function test_over_delivery_outside_tolerance_stays_open_and_tells_the_buyer(): void
    {
        $po = $this->order(10);
        $grn = $this->receive($po, ['accepted_qty' => 12, 'rejected_qty' => 1, 'damaged_qty' => 1]);

        $kinds = ReceiptDiscrepancy::withoutGlobalScopes()->where('grn_id', $grn->id)->pluck('status', 'kind')->all();
        $this->assertSame(['OVER' => 'OPEN', 'DAMAGED' => 'OPEN'], $kinds);
        $this->assertSame('OPEN', $grn->fresh()->discrepancy_status);
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $this->manager->id)->where('data', 'like', '%wms_discrepancy%')->exists());

        $d = ReceiptDiscrepancy::withoutGlobalScopes()->where('grn_id', $grn->id)->where('kind', 'OVER')->firstOrFail();
        $this->actingAs($this->viewer)->post(route('warehouse.inbound.discrepancies.resolve', $d->uuid), ['decision' => 'RESOLVE', 'resolution' => 'ok'])
            ->assertForbidden();
        $this->actingAs($this->manager)->post(route('warehouse.inbound.discrepancies.resolve', $d->uuid), ['decision' => 'RESOLVE', 'resolution' => 'Supplier will invoice 12'])
            ->assertSessionHasNoErrors();
        $this->assertSame('RESOLVED', $d->fresh()->status);
    }

    public function test_over_delivery_within_tolerance_is_accepted_on_the_spot(): void
    {
        $this->asCompany($this->company, fn () => app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory::class)
            ->save(['policies' => ['receipt_over_tolerance_pct' => 10]], $this->store));
        $po = $this->order(10);
        $grn = $this->receive($po, ['accepted_qty' => 11]);

        $d = ReceiptDiscrepancy::withoutGlobalScopes()->where('grn_id', $grn->id)->firstOrFail();
        $this->assertTrue($d->within_tolerance);
        $this->assertSame('ACCEPTED', $d->status);
        $this->assertSame('RESOLVED', $grn->fresh()->discrepancy_status);
    }

    public function test_the_same_delivery_note_cannot_be_received_twice_without_an_exception(): void
    {
        $po = $this->order(10);
        $this->receive($po, ['accepted_qty' => 2], ['delivery_note_ref' => 'DN-5']);

        try {
            $this->receive($po, ['accepted_qty' => 2], ['delivery_note_ref' => 'DN-5'], $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::RECEIVE]));
            $this->fail('A duplicate delivery note was received.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already received', $e->getMessage());
        }

        $grn = $this->receive($po, ['accepted_qty' => 2], ['delivery_note_ref' => 'DN-5', 'allow_duplicate' => true]);
        $this->assertTrue(ReceiptDiscrepancy::withoutGlobalScopes()->where('grn_id', $grn->id)->where('kind', 'DUPLICATE')->exists());
    }

    public function test_a_retried_receipt_does_not_receive_twice(): void
    {
        $po = $this->order(10);
        $a = $this->receive($po, ['accepted_qty' => 2], ['idempotency_key' => 'scanner-77']);
        $b = $this->receive($po, ['accepted_qty' => 2], ['idempotency_key' => 'scanner-77']);

        $this->assertSame($a->id, $b->id);
        $this->assertEquals(2, $po->items[0]->fresh()->received_qty);
    }

    /**
     * QA-002: two people receive the same delivery. The order is locked while a receipt is posted, so
     * the second sees the first; the same delivery note is refused, and with no note the extra is an
     * open over-delivery for the buyer -- never a silent second posting.
     */
    public function test_two_people_receiving_the_same_delivery_do_not_post_it_twice(): void
    {
        $po = $this->order(10);
        $clerkA = $this->person('clerk-a', [RoleCatalog::VIEW, RoleCatalog::RECEIVE]);
        $clerkB = $this->person('clerk-b', [RoleCatalog::VIEW, RoleCatalog::RECEIVE]);
        $this->receive($po, ['accepted_qty' => 10], ['delivery_note_ref' => 'DN-555'], $clerkA);
        try {
            $this->receive($po->fresh('items'), ['accepted_qty' => 10], ['delivery_note_ref' => 'DN-555'], $clerkB);
            $this->fail('The same delivery note was received twice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already received', $e->getMessage());
        }
        $this->assertEquals(10, $po->items[0]->fresh()->received_qty);
        $this->assertSame(1, DB::table('goods_received_notes')->where('purchase_order_id', $po->id)->count());

        $second = $this->receive($po->fresh('items'), ['accepted_qty' => 10], [], $clerkB);
        $this->assertSame('OPEN', ReceiptDiscrepancy::withoutGlobalScopes()->where('grn_id', $second->id)->where('kind', 'OVER')->value('status'),
            'a second receipt with no note is an over-delivery the buyer must decide, not a quiet double');
    }

    // ── Receiving without an order ───────────────────────────────────────────────────────────

    public function test_goods_without_an_order_post_only_when_somebody_else_approves(): void
    {
        $clerk = $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::RECEIVE]);
        $this->actingAs($clerk)->post(route('warehouse.inbound.nonpo.store'), [
            'warehouse' => $this->store->uuid, 'supplier_id' => $this->supplier, 'reason' => 'Supplier sent samples unannounced',
            'lines' => [['item' => $this->item->uuid, 'qty' => 5, 'unit_cost' => 18]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $grn = GoodsReceivedNote::withoutGlobalScopes()->where('source', 'NON_PO')->latest('id')->firstOrFail();

        $this->assertSame('PENDING', $grn->approval_status);
        $this->assertEquals(0, $this->item->fresh()->qty_on_hand, 'An unapproved receipt posted stock.');

        $this->actingAs($clerk)->post(route('warehouse.inbound.receipts.decide', $grn->uuid), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('warehouse.inbound.receipts.decide', $grn->uuid), ['decision' => 'approve', 'note' => 'Checked'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(5, $this->item->fresh()->qty_on_hand);
        $this->assertTrue(ReceiptDiscrepancy::withoutGlobalScopes()->where('grn_id', $grn->id)->where('kind', 'UNEXPECTED')->exists());
    }

    // ── WMS-031 appointments ─────────────────────────────────────────────────────────────────

    public function test_a_dock_cannot_be_double_booked_or_booked_outside_hours(): void
    {
        $this->store->update(['opening_time' => '06:00', 'closing_time' => '18:00', 'timezone' => 'Africa/Johannesburg']);
        $svc = app(DockAppointments::class);
        $day = now('Africa/Johannesburg')->addDay()->setTime(9, 0);
        $a = $this->asCompany($this->company, fn () => $svc->book($this->store->fresh(), ['dock_bin_id' => $this->dock->id, 'slot_start' => $day, 'slot_end' => $day->copy()->addHour()], $this->manager));

        foreach ([[$day->copy()->addMinutes(30), $day->copy()->addMinutes(90), 'already booked'], [$day->copy()->setTime(19, 0), $day->copy()->setTime(20, 0), 'is open']] as [$s, $e, $msg]) {
            try {
                $this->asCompany($this->company, fn () => $svc->book($this->store->fresh(), ['dock_bin_id' => $this->dock->id, 'slot_start' => $s, 'slot_end' => $e], $this->manager));
                $this->fail("Booking accepted: {$msg}");
            } catch (RuntimeException $ex) {
                $this->assertStringContainsString($msg, $ex->getMessage());
            }
        }

        $svc->move($a, 'ARRIVED', $this->manager);
        $this->assertNotNull($a->fresh()->arrived_at);
        $this->expectExceptionMessage('cannot become');
        $svc->move($a->fresh(), 'NO_SHOW', $this->manager);
    }

    public function test_a_missed_slot_is_shown_as_late(): void
    {
        $start = now()->subHours(3);
        $a = $this->asCompany($this->company, fn () => app(DockAppointments::class)->book($this->store, ['slot_start' => $start, 'slot_end' => $start->copy()->addHour()], $this->manager));

        $this->assertTrue($a->fresh()->isOverdue());
        $this->actingAs($this->manager)->get(route('warehouse.inbound.appointments.index', ['warehouse' => $this->store->uuid]))
            ->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/Appointments')->where('queue.0.overdue', true));
    }

    // ── WMS-034 supplier performance ─────────────────────────────────────────────────────────

    public function test_late_deliveries_flag_the_supplier_in_supplier_management_and_recovery_clears_it(): void
    {
        foreach (range(1, 5) as $i) {
            $po = $this->order(1, ['expected_delivery' => now()->subDays(2)->toDateString()]);
            $this->receive($po, ['accepted_qty' => 1]);
        }

        $score = app(SupplierScorecard::class)->scores(now()->subDay(), now()->addDay(), $this->supplier)[0];
        $this->assertSame(5, $score['deliveries']);
        $this->assertEquals(0, $score['on_time_rate']);
        $this->assertTrue(DB::table('supplier_risk_flags')->where('supplier_id', $this->supplier)->where('type', 'delivery_performance')->where('status', 'open')->exists());

        foreach (range(1, 20) as $i) {
            $po = $this->order(1, ['expected_delivery' => now()->addDay()->toDateString()]);
            $this->receive($po, ['accepted_qty' => 1]);
        }
        $this->assertFalse(DB::table('supplier_risk_flags')->where('supplier_id', $this->supplier)->where('type', 'delivery_performance')->where('status', 'open')->exists(),
            'The flag did not clear when the supplier recovered.');
    }

    // ── WMS-035 supplier returns ─────────────────────────────────────────────────────────────

    public function test_rejected_goods_go_back_at_dispatch_reversing_the_accrual(): void
    {
        $po = $this->order(5);
        $grn = $this->receive($po, ['accepted_qty' => 3, 'rejected_qty' => 2, 'keep_rejected' => true]);
        $rejected = FinStockPosition::withoutGlobalScopes()->where('item_id', $this->item->id)->where('status', 'REJECTED')->firstOrFail();
        $this->assertEquals(12, $rejected->qty);

        $svc = app(SupplierReturns::class);
        $this->actingAs($this->manager);
        $ret = $this->asCompany($this->company, fn () => $svc->draft($this->store, $this->supplier, 'Leaking bottles', [
            ['item_id' => $this->item->id, 'qty' => 12, 'bin_id' => $rejected->bin_id, 'stock_status' => 'REJECTED'],
        ], $this->manager, $grn->id));
        $this->assertEquals(12, $rejected->fresh()->qty, 'Stock left at drafting, not at dispatch.');

        $this->asCompany($this->company, function () use ($svc, $ret) {
            $svc->approve($ret, $this->manager);
            $svc->dispatch($ret->fresh('lines'), $this->manager, 'Courier X', 'WB-123');
        });
        $this->assertEquals(0, (float) $rejected->fresh()->qty);
        $m = FinStockMovement::withoutGlobalScopes()->where('type', 'to_supplier')->firstOrFail();
        $debit = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_id', $m->journal_id)->where('l.debit', '>', 0)->value('a.code');
        $this->assertSame('2150', $debit);

        try {
            $svc->cancel($ret->fresh());
            $this->fail('A dispatched return was cancelled.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        $svc->acknowledge($ret->fresh(), 'J. Mokoena');
        $this->expectExceptionMessage('credit note number');
        $svc->close($ret->fresh(), 'CREDIT', null);
    }

    // ── WMS-041 put-away (started by receiving) ──────────────────────────────────────────────

    public function test_put_away_moves_stock_from_the_dock_to_a_permitted_bin(): void
    {
        $po = $this->order(2);
        $grn = $this->receive($po, ['accepted_qty' => 2]);
        $task = PutawayTask::withoutGlobalScopes()->where('source_id', $grn->id)->firstOrFail();
        $blocked = $this->asCompany($this->company, fn () => tap(app(LocationService::class)->create($this->store, ['code' => 'A-02']), fn ($b) => app(LocationService::class)->block($b, 'Broken beam')));

        try {
            $this->asCompany($this->company, fn () => app(PutawayTasks::class)->complete($task, $blocked->fresh(), 12, $this->manager));
            $this->fail('Put away into a blocked bin.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
        }

        $done = $this->asCompany($this->company, fn () => app(PutawayTasks::class)->complete($task->fresh(), $this->shelf, 12, $this->manager));
        $this->assertSame('DONE', $done->status);
        $this->assertEquals(12, (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $this->shelf->id)->sum('qty'));
        $this->assertEquals(0, (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $this->dock->id)->sum('qty'));
    }

    // ── Screens ──────────────────────────────────────────────────────────────────────────────

    public function test_every_inbound_screen_renders(): void
    {
        $po = $this->order(3);
        $grn = $this->receive($po, ['accepted_qty' => 1]);
        $this->actingAs($this->manager);

        $this->get(route('warehouse.inbound.expected'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/Expected')->has('lines', 1));
        $this->get(route('warehouse.inbound.receive.form', $po->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/Receive')->where('lines.0.outstanding', 2));
        $this->get(route('warehouse.inbound.nonpo.form'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/ReceiveWithoutOrder'));
        $this->get(route('warehouse.inbound.receipts.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/Receipts'));
        $this->get(route('warehouse.inbound.receipts.show', $grn->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/ReceiptShow')->has('putaway', 1));
        $this->get(route('warehouse.inbound.appointments.index'))->assertOk();
        $this->get(route('warehouse.inbound.returns.index', ['grn' => $grn->uuid]))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/Returns')->where('prefill.grn', $grn->uuid));
        $this->get(route('warehouse.inbound.scorecards'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Inbound/Scorecards')->has('scores', 1));
        $this->get(route('warehouse.hub.inbound'))->assertOk();

        $this->post(route('warehouse.inbound.receipts.attach', $grn->uuid), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('dn.pdf', '%PDF-1.4 delivery note'), 'kind' => 'DOCUMENT',
        ])->assertSessionHasNoErrors();
        $att = DB::table('wms_receipt_attachments')->where('grn_id', $grn->id)->first();
        $this->get(route('warehouse.inbound.receipts.download', [$grn->uuid, $att->uuid]))->assertOk();

        foreach (['Expected', 'Receive', 'ReceiveWithoutOrder', 'Receipts', 'ReceiptShow', 'Appointments', 'Returns', 'Scorecards'] as $page) {
            $this->assertFileExists(resource_path("js/Modules/Warehouse/Inbound/{$page}.vue"));
        }
    }
}
