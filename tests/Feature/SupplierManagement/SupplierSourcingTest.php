<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\RfxEvent;
use App\Modules\SupplierManagement\Models\RfxResponse;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use Illuminate\Support\Carbon;

/**
 * Sourcing through to payment: RFx → award → purchase order → invoice.
 *
 * This is the part of the module that moves money, so it is the part where "it works" is not the
 * interesting question. What matters is whether the sequence can be subverted — a bid accepted after
 * close, an award to a company that never bid, an invoice paid twice — because each of those is a
 * finding an auditor would raise and none of them looks like a bug from the screen.
 */
class SupplierSourcingTest extends SupplierTestCase
{
    // ── The happy path ───────────────────────────────────────────────────────

    public function test_an_rfx_runs_from_draft_through_to_award(): void
    {
        $supplier = $this->makeSupplier();

        $this->post(route('suppliers.sourcing.store'), [
            'title'        => 'Cleaning services 2026',
            'type'         => 'rfq',
            'closing_date' => Carbon::now()->addWeek()->toDateString(),
            'supplier_ids' => [$supplier->id],
        ])->assertRedirect();

        $rfx = RfxEvent::latest('id')->first();

        $this->assertSame('draft', $rfx->status);
        $this->assertSame($this->companyId, (int) $rfx->company_id);
        $this->assertSame(1, $rfx->invitations()->count());

        $this->post(route('suppliers.sourcing.publish', $rfx->id))->assertRedirect();
        $this->assertSame('published', $rfx->fresh()->status);

        $this->post(route('suppliers.sourcing.responses', $rfx->id), [
            'supplier_id' => $supplier->id,
            'amount'      => 250_000,
            'proposal'    => 'Three-year fixed price.',
        ])->assertRedirect();

        $this->assertSame('responded', $rfx->invitations()->first()->status);
        $this->assertSame($this->companyId, (int) RfxResponse::latest('id')->first()->company_id);

        $this->post(route('suppliers.sourcing.close', $rfx->id))->assertRedirect();
        $this->assertSame('closed', $rfx->fresh()->status);

        $this->post(route('suppliers.sourcing.award', $rfx->id), ['supplier_id' => $supplier->id])
            ->assertRedirect();

        $rfx->refresh();
        $this->assertSame('awarded', $rfx->status);
        $this->assertSame($supplier->id, $rfx->awarded_supplier_id);
    }

    public function test_a_purchase_order_and_an_invoice_can_be_raised_against_the_awarded_supplier(): void
    {
        $supplier = $this->makeSupplier();

        $this->post(route('suppliers.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'title'       => 'Q1 cleaning',
            'amount'      => 60_000,
        ])->assertRedirect();

        $po = PurchaseOrder::latest('id')->first();
        $this->assertSame($this->companyId, (int) $po->company_id);
        $this->assertSame('ZAR', $po->currency);

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number'    => 'INV-0001',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $po->id,
            'amount'            => 60_000,
        ])->assertRedirect();

        $this->assertDatabaseHas('supplier_invoices', [
            'invoice_number' => 'INV-0001',
            'company_id'     => $this->companyId,
        ]);
    }

    public function test_a_suspended_supplier_cannot_receive_a_new_purchase_order(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 2]);

        $this->post(route('suppliers.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'title'       => 'Should not happen',
            'amount'      => 10_000,
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, PurchaseOrder::where('supplier_id', $supplier->id)->count());
    }

    // ── Where the sequence can be subverted ──────────────────────────────────

    /**
     * A tender cannot be awarded to a supplier that never bid.
     *
     * This is the shape of a rigged tender, and the record it used to leave behind was
     * indistinguishable from a clean award — which is why it mattered more than its one-line fix
     * suggests. The award now reads from the bids on the table rather than from the request body.
     */
    public function test_an_rfx_cannot_be_awarded_to_a_supplier_that_never_bid(): void
    {
        $invited = $this->makeSupplier(['company_name' => 'Bidder']);
        $outsider = $this->makeSupplier(['company_name' => 'Never Bid']);

        $rfx = $this->rfx([$invited->id]);
        $this->post(route('suppliers.sourcing.publish', $rfx->id));

        $this->post(route('suppliers.sourcing.responses', $rfx->id), [
            'supplier_id' => $invited->id, 'amount' => 100_000,
        ]);
        $this->post(route('suppliers.sourcing.close', $rfx->id));

        $this->post(route('suppliers.sourcing.award', $rfx->id), ['supplier_id' => $outsider->id])
            ->assertSessionHasErrors('supplier_id');

        $this->assertNull($rfx->fresh()->awarded_supplier_id);
    }

    /**
     * An award away from the lowest bid has to say why.
     *
     * That is the award an auditor asks about, and the moment of the decision is the only time
     * anyone can still remember the reason. The cheapest bid needs no justification.
     */
    public function test_awarding_above_the_lowest_bid_requires_a_reason_on_record(): void
    {
        $cheap = $this->makeSupplier(['company_name' => 'Cheapest']);
        $dearer = $this->makeSupplier(['company_name' => 'Dearer']);

        $rfx = $this->closedRfxWithBids([$cheap->id => 100_000, $dearer->id => 180_000]);

        $this->post(route('suppliers.sourcing.award', $rfx->id), ['supplier_id' => $dearer->id])
            ->assertSessionHasErrors('award_reason');

        $this->assertNull($rfx->fresh()->awarded_supplier_id);

        $this->post(route('suppliers.sourcing.award', $rfx->id), [
            'supplier_id'  => $dearer->id,
            'award_reason' => 'Only bidder able to meet the 14-day lead time.',
        ])->assertRedirect();

        $rfx->refresh();
        $this->assertSame($dearer->id, $rfx->awarded_supplier_id);
        $this->assertSame('Only bidder able to meet the 14-day lead time.', $rfx->award_reason);
        $this->assertSame($this->user->id, (int) $rfx->awarded_by, 'The award must carry the name of whoever made it.');
        $this->assertNotNull($rfx->awarded_at);
    }

    public function test_the_lowest_bid_can_be_awarded_without_a_written_reason(): void
    {
        $cheap = $this->makeSupplier();
        $dearer = $this->makeSupplier();

        $rfx = $this->closedRfxWithBids([$cheap->id => 100_000, $dearer->id => 180_000]);

        $this->post(route('suppliers.sourcing.award', $rfx->id), ['supplier_id' => $cheap->id])
            ->assertRedirect();

        $this->assertSame($cheap->id, $rfx->fresh()->awarded_supplier_id);
    }

    /**
     * The event has to travel draft → published → closed → awarded, and cannot go back.
     *
     * Every transition used to be a bare status write, so a draft could be awarded before anyone was
     * asked to bid, an award could be silently overwritten with a different winner, and a decided
     * event could be pushed back to published. An award that can be quietly replaced is not a record
     * of a decision.
     */
    public function test_the_rfx_lifecycle_cannot_be_skipped_or_rewound(): void
    {
        $first = $this->makeSupplier();
        $second = $this->makeSupplier();

        $rfx = $this->rfx([$first->id, $second->id]);
        $this->assertSame('draft', $rfx->status);

        // Cannot award, or close, before it is even published.
        $this->post(route('suppliers.sourcing.award', $rfx->id), ['supplier_id' => $first->id])
            ->assertSessionHasErrors('status');
        $this->post(route('suppliers.sourcing.close', $rfx->id))->assertSessionHasErrors('status');
        $this->assertNull($rfx->fresh()->awarded_supplier_id);

        $this->post(route('suppliers.sourcing.publish', $rfx->id));
        $this->post(route('suppliers.sourcing.responses', $rfx->id), ['supplier_id' => $first->id, 'amount' => 90_000]);
        $this->post(route('suppliers.sourcing.responses', $rfx->id), ['supplier_id' => $second->id, 'amount' => 95_000]);

        // Publishing twice is not a way back to the start.
        $this->post(route('suppliers.sourcing.publish', $rfx->id))->assertSessionHasErrors('status');

        $this->post(route('suppliers.sourcing.close', $rfx->id));
        $this->post(route('suppliers.sourcing.award', $rfx->id), ['supplier_id' => $first->id])->assertRedirect();
        $this->assertSame($first->id, $rfx->fresh()->awarded_supplier_id);

        // Awarded is terminal: the winner cannot be swapped, and the event cannot reopen.
        $this->post(route('suppliers.sourcing.award', $rfx->id), [
            'supplier_id' => $second->id, 'award_reason' => 'Changed my mind.',
        ])->assertSessionHasErrors('status');
        $this->post(route('suppliers.sourcing.publish', $rfx->id))->assertSessionHasErrors('status');

        $rfx->refresh();
        $this->assertSame($first->id, $rfx->awarded_supplier_id);
        $this->assertSame('awarded', $rfx->status);
    }

    public function test_an_event_nobody_was_invited_to_cannot_be_published(): void
    {
        $rfx = $this->rfx([]);

        $this->post(route('suppliers.sourcing.publish', $rfx->id))->assertSessionHasErrors('status');

        $this->assertSame('draft', $rfx->fresh()->status);
    }

    /** A bid arriving after the closing date is refused. */
    public function test_a_bid_is_refused_after_the_closing_date_has_passed(): void
    {
        $supplier = $this->makeSupplier();

        $rfx = $this->rfx([$supplier->id], closing: Carbon::now()->addDay());
        $this->post(route('suppliers.sourcing.publish', $rfx->id))->assertRedirect();

        // The date passes while the event is open — the ordinary way a tender closes.
        $rfx->forceFill(['closing_date' => Carbon::now()->subMonth()])->save();

        $this->post(route('suppliers.sourcing.responses', $rfx->id), [
            'supplier_id' => $supplier->id, 'amount' => 1,
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, $rfx->responses()->count());
    }

    /** A supplier who was never invited cannot be recorded as having responded. */
    public function test_an_uninvited_supplier_cannot_have_a_bid_recorded(): void
    {
        $invited = $this->makeSupplier();
        $outsider = $this->makeSupplier(['company_name' => 'Not On The List']);

        $rfx = $this->rfx([$invited->id]);
        $this->post(route('suppliers.sourcing.publish', $rfx->id));

        $this->post(route('suppliers.sourcing.responses', $rfx->id), [
            'supplier_id' => $outsider->id, 'amount' => 50_000,
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, $rfx->responses()->count());
    }

    /**
     * A supplier revising its bid replaces it rather than holding several at once.
     *
     * Revision before close is legitimate. What is not is one company appearing three times in the
     * comparison at three prices, which is what stacked responses produced.
     */
    public function test_a_revised_bid_replaces_the_previous_one(): void
    {
        $supplier = $this->makeSupplier();
        $rfx = $this->rfx([$supplier->id]);
        $this->post(route('suppliers.sourcing.publish', $rfx->id));

        foreach ([300_000, 200_000, 100_000] as $amount) {
            $this->post(route('suppliers.sourcing.responses', $rfx->id), [
                'supplier_id' => $supplier->id, 'amount' => $amount,
            ])->assertRedirect();
        }

        $this->assertSame(1, $rfx->responses()->count());
        $this->assertSame(100_000.0, (float) $rfx->responses()->first()->amount);
    }

    /** The same invoice number cannot be captured twice for one supplier. */
    public function test_a_duplicate_invoice_number_is_refused(): void
    {
        $supplier = $this->makeSupplier();

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number' => 'DUP-001', 'supplier_id' => $supplier->id, 'amount' => 45_000,
        ])->assertRedirect();

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number' => 'DUP-001', 'supplier_id' => $supplier->id, 'amount' => 45_000,
        ])->assertSessionHasErrors('invoice_number');

        $this->assertSame(1, SupplierInvoice::where('invoice_number', 'DUP-001')->count());
    }

    /** The same number for a *different* supplier is a different invoice, and is allowed. */
    public function test_two_suppliers_may_each_use_the_same_invoice_number(): void
    {
        $a = $this->makeSupplier();
        $b = $this->makeSupplier();

        foreach ([$a, $b] as $supplier) {
            $this->post(route('suppliers.invoices.store'), [
                'invoice_number' => 'INV-100', 'supplier_id' => $supplier->id, 'amount' => 1_000,
            ])->assertRedirect();
        }

        $this->assertSame(2, SupplierInvoice::where('invoice_number', 'INV-100')->count());
    }

    /**
     * An invoice over its purchase order is held as a variance, and cannot be paid until resolved.
     *
     * Held rather than refused: a genuine price escalation is a conversation, not a fraud, and an
     * invoice the company has actually received has to be recordable. What changes is that it stops
     * looking like every other invoice in the queue.
     */
    public function test_an_invoice_over_its_purchase_order_is_held_as_a_variance(): void
    {
        $supplier = $this->makeSupplier();
        $po = $this->purchaseOrder($supplier->id, 10_000);

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number'    => 'OVER-001',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $po->id,
            'amount'            => 250_000,
        ])->assertRedirect();

        $invoice = SupplierInvoice::where('invoice_number', 'OVER-001')->first();

        $this->assertSame('variance', $invoice->match_status);
        $this->assertSame(240_000.0, (float) $invoice->variance_amount);
        $this->assertNotNull($invoice->matched_at);

        $this->patch(route('suppliers.invoices.update', $invoice->id), ['status' => 'paid'])
            ->assertSessionHasErrors('status');

        $this->assertNotSame('paid', $invoice->fresh()->status);
    }

    /** An invoice within tolerance of its order matches cleanly and can be paid. */
    public function test_an_invoice_matching_its_purchase_order_is_marked_matched(): void
    {
        $supplier = $this->makeSupplier();
        $po = $this->purchaseOrder($supplier->id, 10_000);

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number'    => 'OK-001',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $po->id,
            'amount'            => 10_100,
        ])->assertRedirect();

        $invoice = SupplierInvoice::where('invoice_number', 'OK-001')->first();

        $this->assertSame('matched', $invoice->match_status);

        $this->patch(route('suppliers.invoices.update', $invoice->id), ['status' => 'paid'])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /** An invoice cannot be attached to another supplier's purchase order. */
    public function test_an_invoice_cannot_be_matched_to_a_different_suppliers_purchase_order(): void
    {
        $a = $this->makeSupplier(['company_name' => 'Supplier A']);
        $b = $this->makeSupplier(['company_name' => 'Supplier B']);

        $poForA = $this->purchaseOrder($a->id, 5_000);

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number'    => 'CROSS-001',
            'supplier_id'       => $b->id,
            'purchase_order_id' => $poForA->id,
            'amount'            => 5_000,
        ])->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, SupplierInvoice::where('invoice_number', 'CROSS-001')->count());
    }

    /**
     * An approved order commits the budget, and the invoice against it consumes it.
     *
     * The two must not both count: releasing the order's commitment as the invoice lands is what
     * stops the same money being held as promised and spent at once, which would show a budget
     * exhausted at half the real figure.
     */
    public function test_an_approved_order_commits_the_budget_and_its_invoice_consumes_it(): void
    {
        $budget = $this->makeBudget(1_000_000);
        $supplier = $this->makeSupplier();
        $service = app(\App\Modules\Budget\Services\BudgetService::class);

        $po = $this->purchaseOrder($supplier->id, 200_000);

        // A draft order promises nothing yet.
        $this->assertSame(0.0, $service->amounts($budget)['committed']);

        $this->actingAs($this->makeUser($this->companyId))
            ->patch(route('suppliers.purchase-orders.update', $po), ['status' => 'approved'])
            ->assertRedirect();

        $po->refresh();
        $this->assertNotNull($po->budget_id, 'An approved order must encumber a budget.');
        $this->assertSame(200_000.0, $service->amounts($budget)['committed']);

        $this->actingAs($this->user)->post(route('suppliers.invoices.store'), [
            'invoice_number'    => 'BUD-001',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $po->id,
            'amount'            => 200_000,
        ])->assertRedirect();

        $invoice = SupplierInvoice::where('invoice_number', 'BUD-001')->first();
        $amounts = $service->amounts($budget);

        $this->assertNotNull($invoice->budget_id, 'An invoice must consume a budget.');
        $this->assertSame(200_000.0, $amounts['actual'], 'The invoice should be recorded as spend.');
        $this->assertSame(0.0, $amounts['committed'], 'The order commitment should be released, not double-counted.');
        $this->assertSame(800_000.0, $amounts['available']);
    }

    /**
     * Deleting an invoice restores the commitment its purchase order was holding.
     *
     * Capturing an invoice against an order releases that order's commitment, so the same money is
     * not counted as both promised and spent. Deleting the invoice has to put the commitment back —
     * the order is still outstanding, and money that silently becomes available again is money the
     * company will commit twice.
     */
    public function test_deleting_an_invoice_restores_the_orders_commitment(): void
    {
        $budget = $this->makeBudget(500_000);
        $supplier = $this->makeSupplier();
        $service = app(\App\Modules\Budget\Services\BudgetService::class);

        $po = $this->purchaseOrder($supplier->id, 80_000);

        $this->actingAs($this->makeUser($this->companyId))
            ->patch(route('suppliers.purchase-orders.update', $po), ['status' => 'approved'])
            ->assertRedirect();

        $this->assertSame(80_000.0, $service->amounts($budget)['committed']);

        $this->actingAs($this->user)->post(route('suppliers.invoices.store'), [
            'invoice_number'    => 'RESTORE-001',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $po->id,
            'amount'            => 80_000,
        ])->assertRedirect();

        $this->assertSame(0.0, $service->amounts($budget)['committed'], 'The invoice should release the commitment.');
        $this->assertSame(80_000.0, $service->amounts($budget)['actual']);

        $invoice = SupplierInvoice::where('invoice_number', 'RESTORE-001')->firstOrFail();

        $this->delete(route('suppliers.invoices.destroy', $invoice->id))->assertRedirect();

        $amounts = $service->amounts($budget);

        $this->assertSame(0.0, $amounts['actual'], 'The spend should be reversed.');
        $this->assertSame(
            80_000.0,
            $amounts['committed'],
            'The order is still outstanding, so its commitment must come back.',
        );
        $this->assertSame(420_000.0, $amounts['available']);
    }

    /** Deleting an approved order frees the money it was holding. */
    public function test_deleting_an_approved_order_releases_its_commitment(): void
    {
        $budget = $this->makeBudget(300_000);
        $supplier = $this->makeSupplier();
        $service = app(\App\Modules\Budget\Services\BudgetService::class);

        $po = $this->purchaseOrder($supplier->id, 50_000);

        $this->actingAs($this->makeUser($this->companyId))
            ->patch(route('suppliers.purchase-orders.update', $po), ['status' => 'approved'])
            ->assertRedirect();

        $this->assertSame(50_000.0, $service->amounts($budget)['committed']);

        $this->actingAs($this->user)
            ->delete(route('suppliers.purchase-orders.destroy', $po))
            ->assertRedirect();

        $this->assertSame(
            0.0,
            $service->amounts($budget)['committed'],
            'A deleted order that keeps its commitment is budget nobody can spend and nobody can find.',
        );
        $this->assertSame(300_000.0, $service->amounts($budget)['available']);
    }

    /**
     * Correcting an over-billed invoice and paying it is one action, not two.
     *
     * The variance guard has to judge the invoice as it will be after the edit. Checking the state it
     * was in before means the amount can be fixed to match the order and the payment still refused,
     * with no way round it from the screen.
     */
    public function test_correcting_a_variance_and_paying_can_happen_in_one_step(): void
    {
        $supplier = $this->makeSupplier();
        $po = $this->purchaseOrder($supplier->id, 10_000);

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number'    => 'FIX-001',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $po->id,
            'amount'            => 40_000,
        ])->assertRedirect();

        $invoice = SupplierInvoice::where('invoice_number', 'FIX-001')->firstOrFail();
        $this->assertSame('variance', $invoice->match_status);

        // The captured figure was a typo. Correcting it to the agreed amount and marking it paid
        // should work in the one request the screen actually sends.
        $this->patch(route('suppliers.invoices.update', $invoice->id), [
            'status' => 'paid',
            'amount' => 10_000,
        ])->assertRedirect();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame('matched', $invoice->match_status);
    }

    /** Removing an invoice takes the spend back off the budget. */
    public function test_removing_an_invoice_reverses_its_budget_consumption(): void
    {
        $budget = $this->makeBudget(500_000);
        $supplier = $this->makeSupplier();
        $service = app(\App\Modules\Budget\Services\BudgetService::class);

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number' => 'REV-001', 'supplier_id' => $supplier->id, 'amount' => 120_000,
        ])->assertRedirect();

        $invoice = SupplierInvoice::where('invoice_number', 'REV-001')->firstOrFail();
        $this->assertSame(120_000.0, $service->amounts($budget)['actual']);

        $this->delete(route('suppliers.invoices.destroy', $invoice->id))->assertRedirect();

        $this->assertSame(0.0, $service->amounts($budget)['actual']);
    }

    /** A purchase order cannot move backwards through its statuses. */
    public function test_a_cancelled_purchase_order_cannot_be_moved_back_to_approved(): void
    {
        $supplier = $this->makeSupplier();
        $po = $this->purchaseOrder($supplier->id, 1_000);

        $this->patch(route('suppliers.purchase-orders.update', $po), ['status' => 'cancelled'])
            ->assertRedirect();
        $this->assertSame('cancelled', $po->fresh()->status);

        $this->patch(route('suppliers.purchase-orders.update', $po), ['status' => 'approved'])
            ->assertSessionHasErrors('status');

        $this->assertSame('cancelled', $po->fresh()->status);
    }

    /**
     * The person who raised an order cannot also approve it.
     *
     * Approval is the moment the money is promised. One user raising and approving their own order
     * is the single-operator path, and the permission alone cannot see it — the check has to compare
     * two people.
     */
    public function test_a_purchase_order_cannot_be_approved_by_the_person_who_raised_it(): void
    {
        $supplier = $this->makeSupplier();
        $po = $this->purchaseOrder($supplier->id, 1_000);

        $this->patch(route('suppliers.purchase-orders.update', $po), ['status' => 'approved'])
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $po->fresh()->status);

        // A colleague approves it, and their name goes on the record.
        $approver = $this->makeUser($this->companyId);

        $this->actingAs($approver)
            ->patch(route('suppliers.purchase-orders.update', $po), ['status' => 'approved'])
            ->assertRedirect();

        $po->refresh();
        $this->assertSame('approved', $po->status);
        $this->assertSame($approver->id, (int) $po->approved_by);
        $this->assertNotNull($po->approved_at);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** A draft order for this supplier, raised by the acting user. */
    private function purchaseOrder(int $supplierId, float $amount): PurchaseOrder
    {
        $this->post(route('suppliers.purchase-orders.store'), [
            'supplier_id' => $supplierId,
            'title'       => 'Order '.uniqid(),
            'amount'      => $amount,
        ])->assertRedirect();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    /**
     * An event carried all the way to closed, with the given bids on the table.
     *
     * @param  array<int, float>  $bids supplier id => amount
     */
    private function closedRfxWithBids(array $bids): RfxEvent
    {
        $rfx = $this->rfx(array_keys($bids));

        $this->post(route('suppliers.sourcing.publish', $rfx->id))->assertRedirect();

        foreach ($bids as $supplierId => $amount) {
            $this->post(route('suppliers.sourcing.responses', $rfx->id), [
                'supplier_id' => $supplierId, 'amount' => $amount,
            ])->assertRedirect();
        }

        $this->post(route('suppliers.sourcing.close', $rfx->id))->assertRedirect();

        return $rfx->fresh();
    }

    private function rfx(array $supplierIds, ?Carbon $closing = null): RfxEvent
    {
        $this->post(route('suppliers.sourcing.store'), [
            'title'        => 'Event '.uniqid(),
            'type'         => 'rfq',
            'closing_date' => ($closing ?? Carbon::now()->addWeek())->toDateString(),
            'supplier_ids' => $supplierIds,
        ]);

        return RfxEvent::latest('id')->first();
    }
}
