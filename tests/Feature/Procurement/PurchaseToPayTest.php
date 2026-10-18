<?php

namespace Tests\Feature\Procurement;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\ProcurementRequisition;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseRequestService;
use App\Modules\Procurement\Services\RequisitionService;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchase to pay, end to end: request → requisition → RFQ → award → order → delivery → receipt.
 *
 * This module had no tests at all, and it is the one that moves money. What it does to a budget is
 * not bookkeeping that happens afterwards — it is the whole point of the chain, and it happens in
 * three steps that must line up exactly:
 *
 *   reserve   the request is approved, so the money is spoken for but not owed
 *   commit    the order is placed, so the money is owed to a named supplier
 *   consume   the goods arrived, so the money is spent
 *
 * Each step has to release the one before it. A commitment that does not release its reservation
 * counts the same money twice and takes a budget to nil at half its real spend; a consumption that
 * does not release its commitment does the same thing one stage later. Neither shows up as an error
 * — the figures are simply wrong, on the screen people use to decide whether they can afford
 * something.
 *
 * The gates matter as much as the arithmetic. Every refusal asserted here is one that would
 * otherwise let somebody skip a stage: ordering against a requisition nobody awarded, converting a
 * request nobody approved, or raising a second order for the same requisition.
 */
class PurchaseToPayTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        // As a request inside a tenant does. `company_id` is owned by the trait, not fillable, so
        // without this the fixtures land unowned and RLS hides them from the code under test.
        app(CurrentCompany::class)->set($this->companyId);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function budget(float $allocated = 1_000_000): Budget
    {
        return Budget::create([
            'code' => 'P2P'.Str::random(6), 'name' => 'Purchasing budget',
            'expenditure_type' => 'opex', 'scope_type' => 'general',
            'currency' => 'ZAR', 'allocated_amount' => $allocated, 'status' => 'active',
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'uuid' => (string) Str::uuid(),
            'company_name' => 'Supplier '.Str::random(5),
            'payment_terms' => '30 days',
            'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true,
            'health_safety_compliance' => true,
            'is_active' => true,
            'is_preferred' => false,
        ]);
    }

    /** A request with one line, already costed. */
    private function request(?Budget $budget = null, float $unitPrice = 10_000, float $qty = 2): PurchaseRequest
    {
        $request = app(PurchaseRequestService::class)->create([
            'uuid' => (string) Str::uuid(),
            'budget_id' => $budget?->id,
            'justification' => 'Replacement laptops for the finance team.',
            'currency' => 'ZAR',
            'urgency' => 'normal',
        ]);

        $request->items()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Laptop', 'quantity' => $qty, 'unit_price' => $unitPrice,
            'line_total' => $qty * $unitPrice,
        ]);

        $request->recalcTotal();

        return $request->refresh();
    }

    /** Carry a request to the state the next stage needs, without asserting on the way. */
    private function approvedRequest(Budget $budget, float $unitPrice = 10_000, float $qty = 2): PurchaseRequest
    {
        $request = $this->request($budget, $unitPrice, $qty);
        $reservation = app(BudgetService::class)->reserveBudget(
            $budget, (float) $request->estimated_total, 'purchase_request', $request->id,
        );

        $request->update([
            'status' => 'approved',
            'budget_reservation_id' => $reservation->id,
            'reserved_amount' => $request->estimated_total,
        ]);

        return $request->refresh();
    }

    private function awardedRequisition(Budget $budget, ?Supplier $supplier = null, float $unitPrice = 10_000): ProcurementRequisition
    {
        $request = $this->approvedRequest($budget, $unitPrice);
        $requisition = app(RequisitionService::class)->convertFromRequest($request);

        return app(RequisitionService::class)->chooseSupplier($requisition, ($supplier ?? $this->supplier())->id);
    }

    // ── The request ──────────────────────────────────────────────────────────

    public function test_a_request_starts_as_a_draft_and_totals_its_lines(): void
    {
        $request = $this->request($this->budget());

        $this->assertSame('draft', $request->status);
        $this->assertEqualsWithDelta(20_000, (float) $request->estimated_total, 0.01);
        $this->assertNotEmpty($request->request_number);
    }

    public function test_submitting_records_what_happened_rather_than_only_the_new_status(): void
    {
        /*
         * The history is the only account of who moved a purchase along. Without it a disputed order
         * has a status and no story, which is exactly the point at which somebody asks.
         */
        $request = $this->request($this->budget());

        app(PurchaseRequestService::class)->submit($request);

        $this->assertGreaterThan(0, $request->history()->count());
    }

    public function test_a_cancelled_request_cannot_become_a_requisition(): void
    {
        $request = $this->request($this->budget());
        app(PurchaseRequestService::class)->cancel($request);

        $this->expectException(\RuntimeException::class);
        app(RequisitionService::class)->convertFromRequest($request->refresh());
    }

    public function test_only_an_approved_request_converts(): void
    {
        // The approval is the control. A draft converting straight to a requisition is the whole
        // governance chain skipped in one call.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/approved/i');

        app(RequisitionService::class)->convertFromRequest($this->request($this->budget()));
    }

    // ── The requisition ──────────────────────────────────────────────────────

    public function test_conversion_carries_the_lines_the_budget_and_the_reservation(): void
    {
        $budget = $this->budget();
        $request = $this->approvedRequest($budget);

        $requisition = app(RequisitionService::class)->convertFromRequest($request);

        $this->assertSame($budget->id, $requisition->budget_id);
        $this->assertSame($request->budget_reservation_id, $requisition->budget_reservation_id);
        $this->assertTrue((bool) $requisition->reservation_confirmed);
        $this->assertCount(1, $requisition->items);
        $this->assertEqualsWithDelta(20_000, (float) $requisition->estimated_total, 0.01);
        $this->assertSame('converted', $request->refresh()->status);
    }

    public function test_a_request_cannot_be_converted_twice(): void
    {
        // Two requisitions from one request means two orders and two commitments against one
        // reservation — the money counted twice, with nothing on screen to say so.
        $request = $this->approvedRequest($this->budget());
        app(RequisitionService::class)->convertFromRequest($request);

        $this->expectException(\RuntimeException::class);
        app(RequisitionService::class)->convertFromRequest($request->refresh());
    }

    public function test_confirming_the_reservation_reports_honestly_when_there_is_none(): void
    {
        /*
         * This is the check that tells a buying officer the money is still there before they go to
         * market. Answering "confirmed" for a requisition with no live reservation would send them
         * out to get quotes against funds that may already be spent.
         */
        $budget = $this->budget();
        $request = $this->approvedRequest($budget);
        $requisition = app(RequisitionService::class)->convertFromRequest($request);

        $this->assertTrue(app(RequisitionService::class)->confirmReservation($requisition)['ok']);

        DB::table('budget_reservations')->where('id', $requisition->budget_reservation_id)
            ->update(['status' => 'released']);

        $result = app(RequisitionService::class)->confirmReservation($requisition->refresh());

        $this->assertFalse($result['ok']);
        $this->assertFalse((bool) $requisition->refresh()->reservation_confirmed);
    }

    public function test_an_rfq_invites_each_supplier_once_and_moves_the_requisition_to_quoting(): void
    {
        $budget = $this->budget();
        $requisition = app(RequisitionService::class)->convertFromRequest($this->approvedRequest($budget));
        $a = $this->supplier();
        $b = $this->supplier();

        // The same supplier twice is a slip on a multi-select, not an intention to invite them twice.
        $rfx = app(RequisitionService::class)->createRfq($requisition, [$a->id, $b->id, $a->id]);

        $this->assertSame(2, DB::table('rfx_invitations')->where('rfx_id', $rfx->id)->count());
        $this->assertSame('quoting', $requisition->refresh()->status);
        $this->assertSame($rfx->id, $requisition->rfx_event_id);
    }

    // ── The order, and the money ─────────────────────────────────────────────

    public function test_an_order_cannot_be_raised_before_the_requisition_is_awarded(): void
    {
        $budget = $this->budget();
        $requisition = app(RequisitionService::class)->convertFromRequest($this->approvedRequest($budget));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/awarded/i');

        app(PurchaseOrderService::class)->generateFromRequisition($requisition);
    }

    public function test_raising_the_order_turns_the_reservation_into_a_commitment_rather_than_adding_to_it(): void
    {
        /*
         * The single most consequential line in this module. The money was already set aside when the
         * request was approved; placing the order makes it owed to a named supplier. If the
         * commitment is added without releasing the reservation, the same R20,000 is counted twice
         * and the budget reports half the room it really has — silently, on the screen people use to
         * decide whether they can afford the next thing.
         */
        $budget = $this->budget(100_000);
        $requisition = $this->awardedRequisition($budget);

        $before = app(BudgetService::class)->amounts($budget);
        $this->assertEqualsWithDelta(20_000, $before['reserved'], 0.01);
        $this->assertEqualsWithDelta(0, $before['committed'], 0.01);

        $po = app(PurchaseOrderService::class)->generateFromRequisition($requisition);

        $after = app(BudgetService::class)->amounts($budget->refresh());

        $this->assertEqualsWithDelta(0, $after['reserved'], 0.01, 'the reservation was not released');
        $this->assertEqualsWithDelta(20_000, $after['committed'], 0.01);
        $this->assertEqualsWithDelta(80_000, $after['available'], 0.01, 'the same money was counted twice');
        $this->assertNotNull($po->budget_commitment_id);
    }

    public function test_the_order_carries_the_requisitions_lines_and_supplier(): void
    {
        $budget = $this->budget();
        $supplier = $this->supplier();
        $requisition = $this->awardedRequisition($budget, $supplier);

        $po = app(PurchaseOrderService::class)->generateFromRequisition($requisition);

        $this->assertSame($supplier->id, $po->supplier_id);
        $this->assertCount(1, $po->items);
        $this->assertEqualsWithDelta(20_000, (float) $po->amount, 0.01);
        $this->assertSame('draft', $po->status);
        $this->assertSame('po_created', $requisition->refresh()->status);
    }

    public function test_one_requisition_cannot_raise_two_orders(): void
    {
        // Two orders means two commitments against one reservation, and a supplier delivering twice.
        $requisition = $this->awardedRequisition($this->budget());
        app(PurchaseOrderService::class)->generateFromRequisition($requisition);

        $this->expectException(\RuntimeException::class);
        app(PurchaseOrderService::class)->generateFromRequisition($requisition->refresh());
    }

    public function test_an_award_with_no_supplier_is_refused(): void
    {
        $budget = $this->budget();
        $requisition = app(RequisitionService::class)->convertFromRequest($this->approvedRequest($budget));
        $requisition->update(['status' => 'awarded']);   // awarded, but nobody was named

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/supplier/i');

        app(PurchaseOrderService::class)->generateFromRequisition($requisition->refresh());
    }

    // ── Receipt ──────────────────────────────────────────────────────────────

    public function test_receiving_goods_turns_the_commitment_into_real_spend(): void
    {
        /*
         * The last of the three steps, and the same hazard as the first: a consumption that does not
         * release its commitment leaves the money counted twice over, this time for good, because
         * nothing after receipt ever revisits it.
         */
        $budget = $this->budget(100_000);
        $po = app(PurchaseOrderService::class)->generateFromRequisition($this->awardedRequisition($budget));

        $item = $po->items->first();
        app(PurchaseOrderService::class)->createGRN($po, [
            ['item_id' => $item->id, 'accepted_qty' => 2, 'rejected_qty' => 0],
        ]);

        $after = app(BudgetService::class)->amounts($budget->refresh());

        $this->assertEqualsWithDelta(0, $after['committed'], 0.01, 'the commitment was not released');
        $this->assertEqualsWithDelta(20_000, $after['actual'], 0.01);
        $this->assertEqualsWithDelta(80_000, $after['available'], 0.01);
    }

    public function test_a_partial_delivery_only_spends_what_actually_arrived(): void
    {
        // Half the order delivered is half the money spent. Consuming the whole commitment on the
        // first delivery note would report the budget as spent and leave the rest owed to nobody.
        $budget = $this->budget(100_000);
        $po = app(PurchaseOrderService::class)->generateFromRequisition($this->awardedRequisition($budget));

        $item = $po->items->first();
        app(PurchaseOrderService::class)->createGRN($po, [
            ['item_id' => $item->id, 'accepted_qty' => 1, 'rejected_qty' => 0],
        ]);

        $after = app(BudgetService::class)->amounts($budget->refresh());

        $this->assertEqualsWithDelta(10_000, $after['actual'], 0.01);
        $this->assertEqualsWithDelta(1, (float) $po->items->first()->refresh()->received_qty, 0.01);
    }

    public function test_rejected_goods_are_recorded_but_not_paid_for(): void
    {
        /*
         * Damaged stock that arrives is still a delivery, and it still has to appear on the note —
         * but counting it as spend means paying for goods that were sent back.
         */
        $budget = $this->budget(100_000);
        $po = app(PurchaseOrderService::class)->generateFromRequisition($this->awardedRequisition($budget));

        $item = $po->items->first();
        $grn = app(PurchaseOrderService::class)->createGRN($po, [
            ['item_id' => $item->id, 'accepted_qty' => 1, 'rejected_qty' => 1, 'condition' => 'damaged'],
        ]);

        $this->assertEqualsWithDelta(10_000, (float) $grn->received_value, 0.01);
        $this->assertEqualsWithDelta(
            10_000, app(BudgetService::class)->amounts($budget->refresh())['actual'], 0.01,
        );
    }

    public function test_a_receipt_naming_an_item_from_another_order_is_ignored(): void
    {
        // The item id comes off a form. Accepting one that belongs elsewhere would spend this
        // budget on another order's goods.
        $budget = $this->budget(100_000);
        $po = app(PurchaseOrderService::class)->generateFromRequisition($this->awardedRequisition($budget));
        $other = app(PurchaseOrderService::class)->generateFromRequisition($this->awardedRequisition($this->budget()));

        $grn = app(PurchaseOrderService::class)->createGRN($po, [
            ['item_id' => $other->items->first()->id, 'accepted_qty' => 2],
        ]);

        $this->assertEqualsWithDelta(0, (float) $grn->received_value, 0.01);
    }

    public function test_the_whole_chain_leaves_the_budget_exactly_where_it_should_be(): void
    {
        /*
         * The three steps together, on one budget, with nothing else touching it. Every stage moves
         * the same R20,000 from one column to the next, and at no point does the total spoken for
         * exceed it.
         */
        $budget = $this->budget(100_000);
        $budgets = app(BudgetService::class);

        $request = $this->approvedRequest($budget);
        $this->assertEqualsWithDelta(80_000, $budgets->amounts($budget)['available'], 0.01);

        $requisition = app(RequisitionService::class)->convertFromRequest($request);
        $requisition = app(RequisitionService::class)->chooseSupplier($requisition, $this->supplier()->id);
        $this->assertEqualsWithDelta(80_000, $budgets->amounts($budget->refresh())['available'], 0.01);

        $po = app(PurchaseOrderService::class)->generateFromRequisition($requisition);
        $this->assertEqualsWithDelta(80_000, $budgets->amounts($budget->refresh())['available'], 0.01);

        app(PurchaseOrderService::class)->createGRN($po, [
            ['item_id' => $po->items->first()->id, 'accepted_qty' => 2],
        ]);

        $final = $budgets->amounts($budget->refresh());
        $this->assertEqualsWithDelta(80_000, $final['available'], 0.01);
        $this->assertEqualsWithDelta(20_000, $final['actual'], 0.01);
        $this->assertEqualsWithDelta(0, $final['reserved'], 0.01);
        $this->assertEqualsWithDelta(0, $final['committed'], 0.01);
    }

    public function test_every_stage_is_scoped_to_the_tenant_that_created_it(): void
    {
        // The whole chain is reachable by id from a form. One company reading another's orders is
        // the failure this module can least afford.
        $budget = $this->budget();
        $po = app(PurchaseOrderService::class)->generateFromRequisition($this->awardedRequisition($budget));

        foreach ([PurchaseRequest::class, ProcurementRequisition::class, PurchaseOrder::class] as $model) {
            $this->assertContains('company_id', (new $model)->getConnection()
                ->getSchemaBuilder()->getColumnListing((new $model)->getTable()));
        }

        $this->assertSame($this->companyId, (int) $po->company_id);
    }
}
