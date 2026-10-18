<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\Budget\Services\BudgetLedgerBridge;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\SupplierInvoice;

/**
 * Put supplier spend where the budget can see it.
 *
 * `purchase_orders.budget_id` / `budget_commitment_id` and `supplier_invoices.budget_id` /
 * `budget_consumption_id` have existed since those tables were created and stayed null, so available
 * budget read the same after a million rand of orders as it did before. Payroll, claims, fleet and
 * contracts all post through `BudgetLedgerBridge`; this is the door supplier spend was missing.
 *
 * The two events are deliberately different in kind. An approved purchase order is a *commitment* —
 * money promised, nothing paid, and the point at which a blocking budget rule should be able to say
 * no. An invoice is *spend* — the obligation already exists, so refusing to record it would only
 * make the books wrong. That asymmetry is the bridge's own contract, and this respects it.
 *
 * Nothing here throws. A budget that is misconfigured, or a tenant that has not bought the Budget
 * module at all, must not stop a company recording what it has ordered and been billed for.
 */
class SupplierBudgetPoster
{
    public function __construct(private readonly BudgetLedgerBridge $bridge) {}

    /**
     * Commit the budget when an order is approved, and release it if the order is cancelled.
     *
     * Keyed on `po:{id}` so an order approved twice — a double-click, a status toggled back and forth
     * — commits once. Draft orders post nothing: a draft is not yet a promise to anyone.
     */
    public function syncPurchaseOrder(PurchaseOrder $order, ?string $previousStatus = null): void
    {
        $reference = "po:{$order->id}";
        $committed = in_array($order->status, ['approved', 'received', 'closed'], true);
        $wasCommitted = in_array((string) $previousStatus, ['approved', 'received', 'closed'], true);

        if ($committed && ! $wasCommitted) {
            $result = $this->bridge->postCommitment(
                sourceType: 'supplier_purchase_order',
                sourceId: $order->id,
                amount: (float) $order->amount,
                when: $order->order_date,
                scope: ['expenditure_type' => 'opex'],
                reference: $reference,
                note: __('supplier_management.budget.po_note', ['po' => $order->po_number]),
            );

            if ($result->landed()) {
                $order->forceFill([
                    'budget_id'            => $result->budgetId,
                    'budget_commitment_id' => $result->ledgerId,
                ])->saveQuietly();
            }

            return;
        }

        // Cancelled after being approved: the money was held and is no longer owed. Leaving it held
        // is how a budget quietly runs out of room it actually has.
        if ($wasCommitted && $order->status === 'cancelled') {
            $this->bridge->release($reference);

            $order->forceFill(['budget_id' => null, 'budget_commitment_id' => null])->saveQuietly();
        }
    }

    /**
     * Consume the budget when an invoice is captured.
     *
     * Where the invoice matches a purchase order, the commitment that order made is released first —
     * otherwise the same money is counted twice, once as promised and once as spent, and the budget
     * reads as exhausted at half the real figure.
     */
    public function recordInvoice(SupplierInvoice $invoice): void
    {
        if ($invoice->purchase_order_id) {
            $this->bridge->release("po:{$invoice->purchase_order_id}");
        }

        $result = $this->bridge->postSpend(
            sourceType: 'supplier_invoice',
            sourceId: $invoice->id,
            amount: (float) $invoice->amount,
            when: $invoice->invoice_date,
            scope: ['budget_id' => $invoice->budget_id, 'expenditure_type' => 'opex'],
            reference: "supplier_invoice:{$invoice->id}",
            note: __('supplier_management.budget.invoice_note', ['number' => $invoice->invoice_number]),
        );

        if ($result->landed()) {
            $invoice->forceFill([
                'budget_id'              => $result->budgetId,
                'budget_consumption_id'  => $result->ledgerId,
            ])->saveQuietly();
        }
    }

    /** Take the spend back off the budget when an invoice is removed or voided. */
    public function reverseInvoice(SupplierInvoice $invoice): void
    {
        $this->bridge->reverseSpend("supplier_invoice:{$invoice->id}");

        // The order's commitment was released when this invoice landed. Restoring it keeps the
        // outstanding order visible as money still owed rather than money now free.
        //
        // Reinstated rather than re-posted: `postCommitment` matches on reference regardless of
        // status, so a fresh posting under `po:{id}` would find the released row, call it a
        // duplicate, and do nothing at all.
        if ($invoice->purchase_order_id) {
            $this->bridge->reinstateCommitment("po:{$invoice->purchase_order_id}");
        }
    }
}
