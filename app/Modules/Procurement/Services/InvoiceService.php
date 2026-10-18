<?php

namespace App\Modules\Procurement\Services;

use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invoice capture: create from a PO (prefilling lines), create manually, and
 * rebuild line items. The AI extraction + matching live in their own services.
 */
class InvoiceService
{
    public function createFromPO(PurchaseOrder $po, array $data, ?int $userId = null): SupplierInvoice
    {
        return DB::transaction(function () use ($po, $data, $userId) {
            $po->loadMissing('items');

            $invoice = SupplierInvoice::create([
                'invoice_number'    => $data['invoice_number'] ?? $this->generateNumber(),
                'supplier_id'       => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'budget_id'         => $po->budget_id,
                'amount'            => $data['amount'] ?? $po->amount,
                'subtotal'          => $data['subtotal'] ?? $po->amount,
                'tax_amount'        => $data['tax_amount'] ?? 0,
                'currency'          => $po->currency ?: 'ZAR',
                'status'            => 'pending',
                'source'            => 'manual',
                'invoice_date'      => $data['invoice_date'] ?? now()->toDateString(),
                'due_date'          => $data['due_date'] ?? null,
                'created_by'        => $userId,
            ]);

            // Prefill invoice lines from the PO (the clerk can adjust before matching).
            foreach ($po->items as $pi) {
                $invoice->items()->create([
                    'name'                   => $pi->name,
                    'purchase_order_item_id' => $pi->id,
                    'quantity'               => $pi->quantity,
                    'unit_price'             => $pi->unit_price,
                    'line_total'             => $pi->line_total,
                ]);
            }

            return $invoice->refresh();
        });
    }

    public function createManual(array $data, ?int $userId = null): SupplierInvoice
    {
        return SupplierInvoice::create([
            'invoice_number'    => $data['invoice_number'] ?? $this->generateNumber(),
            'supplier_id'       => $data['supplier_id'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'budget_id'         => $data['budget_id'] ?? null,
            'amount'            => $data['amount'] ?? 0,
            'subtotal'          => $data['subtotal'] ?? ($data['amount'] ?? 0),
            'tax_amount'        => $data['tax_amount'] ?? 0,
            'currency'          => $data['currency'] ?? 'ZAR',
            'status'            => 'pending',
            'source'            => 'manual',
            'invoice_date'      => $data['invoice_date'] ?? now()->toDateString(),
            'due_date'          => $data['due_date'] ?? null,
            'created_by'        => $userId,
        ]);
    }

    /** Replace the invoice line items and recompute totals. */
    public function setItems(SupplierInvoice $invoice, array $items): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $items) {
            $invoice->items()->delete();
            $subtotal = 0.0;
            $tax = 0.0;
            foreach ($items as $li) {
                if (trim((string) ($li['name'] ?? '')) === '') {
                    continue;
                }
                $line = $invoice->items()->create([
                    'name'       => $li['name'],
                    'sku'        => $li['sku'] ?? null,
                    'quantity'   => $li['quantity'] ?? 1,
                    'unit_price' => $li['unit_price'] ?? 0,
                    'line_total' => $li['line_total'] ?? 0,
                    'tax'        => $li['tax'] ?? 0,
                ]);
                $subtotal += (float) $line->line_total;
                $tax += (float) $line->tax;
            }
            $invoice->update([
                'subtotal'   => round($subtotal, 2),
                'tax_amount' => round($tax, 2),
                'amount'     => round($subtotal + $tax, 2),
            ]);

            return $invoice->refresh();
        });
    }

    private function generateNumber(): string
    {
        $max = (int) SupplierInvoice::withTrashed()->max('id');

        return 'INV'.date('Y').Str::padLeft((string) ($max + 1), 5, '0');
    }
}
