<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetCommitment;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\InvoiceMatchResult;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;

/**
 * PO ↔ GRN ↔ Invoice 3-way matching and finance reconciliation.
 *
 *  match()      compares the invoice against its PO and goods received, flags any
 *               quantity/price/amount discrepancy outside tolerance.
 *  reconcile()  settles the invoice: posts the price variance to the budget actual
 *               (so actual == invoiced) and releases the PO's residual commitment.
 */
class InvoiceMatchingService
{
    public function __construct(private readonly BudgetService $budgets) {}

    /** Run a 3-way (or 2-way when no GRN) match and record the result. */
    public function match(SupplierInvoice $invoice, float $tolerancePct = 2.0, ?int $userId = null): InvoiceMatchResult
    {
        $po = $invoice->purchase_order_id ? PurchaseOrder::with('items', 'goodsReceivedNotes')->find($invoice->purchase_order_id) : null;
        if (! $po) {
            throw new \RuntimeException('Link the invoice to a purchase order before matching.');
        }

        $invoice->loadMissing('items');

        $poAmount      = (float) $po->amount;
        $grnValue      = (float) $po->goodsReceivedNotes->sum('received_value');
        $invoiceAmount = (float) $invoice->amount;
        $hasGrn        = $po->goodsReceivedNotes->isNotEmpty();
        $type          = $hasGrn ? '3way' : '2way';
        $base          = $hasGrn ? $grnValue : $poAmount;

        $tol           = max(0, $tolerancePct) / 100;
        $discrepancies = [];

        // Amount check: invoice vs PO.
        $amountOk = $this->within($invoiceAmount, $poAmount, $tol);
        if (! $amountOk) {
            $discrepancies[] = ['type' => 'amount', 'message' => "Invoice {$invoiceAmount} differs from PO {$poAmount}."];
        }

        // GRN value check (only meaningful with goods received).
        $grnOk = ! $hasGrn || $this->within($invoiceAmount, $grnValue, $tol);
        if ($hasGrn && ! $grnOk) {
            $discrepancies[] = ['type' => 'grn', 'message' => "Invoice {$invoiceAmount} differs from goods received value {$grnValue}."];
        }

        // Quantity check: invoiced qty must not exceed received (3-way) / ordered (2-way) qty.
        $invoicedQty = (float) $invoice->items->sum('quantity');
        $receivedQty = (float) $po->items->sum('received_qty');
        $orderedQty  = (float) $po->items->sum('quantity');
        $qtyCeiling  = $hasGrn ? $receivedQty : $orderedQty;
        $qtyOk       = $invoicedQty <= 0 || $qtyCeiling <= 0 ? true : $invoicedQty <= $qtyCeiling + 0.001;
        if (! $qtyOk) {
            $discrepancies[] = ['type' => 'quantity', 'message' => "Invoiced qty {$invoicedQty} exceeds ".($hasGrn ? 'received' : 'ordered')." qty {$qtyCeiling}."];
        }

        // Price check: invoice line unit prices vs PO line unit prices (matched by sku/name).
        $priceOk = $this->priceCheck($invoice, $po, $tol, $discrepancies);

        $variance        = round($invoiceAmount - $base, 2);
        $withinTolerance = abs($variance) <= round($base * $tol, 2) + 0.001;
        $status          = ($amountOk && $grnOk && $qtyOk && $priceOk && $withinTolerance) ? 'matched' : 'exception';

        return DB::transaction(function () use ($invoice, $po, $type, $status, $poAmount, $grnValue, $invoiceAmount, $hasGrn, $qtyOk, $priceOk, $amountOk, $withinTolerance, $tolerancePct, $variance, $discrepancies, $userId) {
            $result = InvoiceMatchResult::create([
                'supplier_invoice_id'    => $invoice->id,
                'type'                   => $type,
                'status'                 => $status,
                'purchase_order_id'      => $po->id,
                'goods_received_note_id' => $hasGrn ? $po->goodsReceivedNotes->sortByDesc('id')->first()?->id : null,
                'po_amount'              => $poAmount,
                'grn_value'              => $grnValue,
                'invoice_amount'         => $invoiceAmount,
                'qty_ok'                 => $qtyOk,
                'price_ok'               => $priceOk,
                'amount_ok'              => $amountOk,
                'within_tolerance'       => $withinTolerance,
                'tolerance_pct'          => $tolerancePct,
                'variance_amount'        => $variance,
                'discrepancies'          => json_encode($discrepancies),
                'checked_by'             => $userId,
            ]);

            $invoice->update([
                'match_status'           => $status,
                'matched_at'             => now(),
                'status'                 => $status === 'matched' ? 'matched' : 'exception',
                'variance_amount'        => $variance,
                'budget_id'              => $invoice->budget_id ?: $po->budget_id,
                'goods_received_note_id' => $result->goods_received_note_id,
            ]);

            return $result;
        });
    }

    /**
     * Reconcile a matched invoice. action: accept|adjust|dispute.
     *  accept/adjust → posts the variance to the budget actual (actual == invoiced)
     *                   and releases the PO's residual commitment.
     *  dispute       → flags disputed, no budget movement.
     */
    public function reconcile(SupplierInvoice $invoice, string $action = 'accept', ?string $note = null, ?int $userId = null): SupplierInvoice
    {
        if ($invoice->reconciled_at) {
            throw new \RuntimeException('This invoice is already reconciled.');
        }
        if ($invoice->match_status === 'unmatched') {
            throw new \RuntimeException('Run the 3-way match before reconciling.');
        }

        if ($action === 'dispute') {
            $invoice->update([
                'status'         => 'disputed',
                'payment_status' => 'unpaid',
                'notes'          => trim((string) $invoice->notes."\nDispute: ".(string) $note),
            ]);

            return $invoice->refresh();
        }

        return DB::transaction(function () use ($invoice, $note, $userId) {
            $budget = $invoice->budget_id ? Budget::find($invoice->budget_id) : null;
            $po     = $invoice->purchase_order_id ? PurchaseOrder::find($invoice->purchase_order_id) : null;

            $consumptionId = null;
            if ($budget && $po) {
                $grnValue = (float) $po->goodsReceivedNotes()->sum('received_value');
                $delta    = round((float) $invoice->amount - $grnValue, 2); // make actual == invoiced

                if (abs($delta) > 0.005) {
                    $consumption = $this->budgets->consumeBudget(
                        $budget, $delta, 'supplier_invoice', $invoice->id, null,
                        'Invoice reconciliation variance ('.$invoice->invoice_number.')'
                    );
                    $consumptionId = $consumption->id;
                }

                // Release the PO's residual commitment — the invoice is the final settlement.
                if ($po->budget_commitment_id) {
                    BudgetCommitment::where('id', $po->budget_commitment_id)
                        ->where('status', 'active')
                        ->update(['status' => 'released', 'amount' => 0]);
                }
            }

            $invoice->update([
                'status'                => 'reconciled',
                'payment_status'        => 'approved',
                'reconciled_at'         => now(),
                'reconciled_by'         => $userId,
                'budget_consumption_id' => $consumptionId,
                'notes'                 => $note ? trim((string) $invoice->notes."\nReconcile: ".$note) : $invoice->notes,
            ]);

            return $invoice->refresh();
        });
    }

    public function markPaid(SupplierInvoice $invoice, ?int $userId = null): SupplierInvoice
    {
        if (! $invoice->reconciled_at) {
            throw new \RuntimeException('Reconcile the invoice before marking it paid.');
        }
        $invoice->update(['payment_status' => 'paid', 'status' => 'paid']);

        return $invoice->refresh();
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    private function within(float $a, float $b, float $tol): bool
    {
        if ($b == 0.0) {
            return abs($a) <= 0.005;
        }

        return abs($a - $b) <= abs($b) * $tol + 0.005;
    }

    private function priceCheck(SupplierInvoice $invoice, PurchaseOrder $po, float $tol, array &$discrepancies): bool
    {
        $poByKey = [];
        foreach ($po->items as $pi) {
            $poByKey[$this->key($pi->name, $pi->sku ?? null)] = (float) $pi->unit_price;
        }

        $ok = true;
        foreach ($invoice->items as $ii) {
            $k = $this->key($ii->name, $ii->sku);
            if (! isset($poByKey[$k])) {
                continue; // unmatched line — covered by amount/qty checks
            }
            if (! $this->within((float) $ii->unit_price, $poByKey[$k], $tol)) {
                $ok = false;
                $discrepancies[] = ['type' => 'price', 'message' => "Unit price for '{$ii->name}' invoiced at {$ii->unit_price} vs PO {$poByKey[$k]}."];
            }
        }

        return $ok;
    }

    private function key(?string $name, ?string $sku): string
    {
        if ($sku) {
            return 'sku:'.strtolower(trim($sku));
        }

        return 'name:'.strtolower(trim((string) $name));
    }
}
