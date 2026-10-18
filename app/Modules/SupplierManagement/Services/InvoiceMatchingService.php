<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use Illuminate\Validation\ValidationException;

/**
 * Match an invoice against the order it claims to be for.
 *
 * `supplier_invoices` has carried `match_status`, `matched_at` and `variance_amount` since it was
 * created, and nothing ever wrote them — so an invoice for twenty-five times the value of its
 * purchase order sat in the queue looking exactly like a correct one. The columns describe a control
 * that was designed and never built; this builds it.
 *
 * Two of the three ways this goes wrong are refused outright rather than flagged, because they are
 * not variances at all — they are the invoice naming the wrong document. An invoice for a supplier
 * other than the one the order was raised against, and a second invoice under a number this supplier
 * has already used, are both mistakes no tolerance should absorb.
 */
class InvoiceMatchingService
{
    /**
     * How far over the order an invoice may go before it stops being a rounding difference.
     *
     * Read from configuration rather than fixed here: what counts as a rounding difference depends on
     * what a company buys, and a constant would have made `config/suppliers.php` advertise a setting
     * that changed nothing. Deliberately small, and deliberately one-sided in effect — over-tolerance
     * is held for review rather than rejected, because a genuine price escalation is a conversation
     * rather than a fraud, while under-billing matches cleanly since a supplier charging less than
     * agreed is not a control failure.
     */
    private function tolerancePercent(): float
    {
        return (float) config('suppliers.invoice_tolerance_percent', 2.0);
    }

    /**
     * Decide the match state for an invoice about to be written.
     *
     * @return array{match_status: string, matched_at: ?\Illuminate\Support\Carbon, variance_amount: float}
     */
    public function evaluate(?PurchaseOrder $order, float $amount, int $supplierId): array
    {
        if (! $order) {
            // Nothing to match against. This is legitimate — not all spend goes through an order —
            // but it should read as unmatched rather than as having passed a check. The variance is
            // zero rather than null: there is no order to differ from, and the column is NOT NULL.
            return ['match_status' => 'unmatched', 'matched_at' => null, 'variance_amount' => 0.0];
        }

        if ((int) $order->supplier_id !== $supplierId) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('supplier_management.invoices.errors.order_belongs_to_other_supplier', [
                    'po' => $order->po_number,
                ]),
            ]);
        }

        $variance = round($amount - (float) $order->amount, 2);
        $ceiling  = (float) $order->amount * (1 + $this->tolerancePercent() / 100);

        return [
            'match_status'    => $amount <= $ceiling ? 'matched' : 'variance',
            'matched_at'      => now(),
            'variance_amount' => $variance,
        ];
    }

    /**
     * Refuse an invoice number this supplier has already used.
     *
     * There is a unique index behind this too. The index is what makes it true under a double-submit
     * or a concurrent import; this check is what turns the collision into a sentence someone can act
     * on instead of a database error.
     */
    public function assertNotDuplicate(int $supplierId, string $number, ?int $ignoreId = null): void
    {
        $exists = SupplierInvoice::where('supplier_id', $supplierId)
            ->where('invoice_number', $number)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'invoice_number' => __('supplier_management.invoices.errors.duplicate_number', [
                    'number' => $number,
                ]),
            ]);
        }
    }

}
