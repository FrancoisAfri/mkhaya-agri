<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinDeliveryNote;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sales orders + delivery notes (quote → SO → delivery → invoice). Orders and
 * delivery notes don't post; invoicing an order posts through the shared engine.
 */
class SalesOrderService
{
    public function __construct(private ArApService $arap)
    {
    }

    public function save(array $data, ?FinSalesOrder $order = null): FinSalesOrder
    {
        $party = FinParty::findOrFail($data['party_id']);
        [$lines, $subtotal, $tax, $total] = $this->computeLines($data['lines'], (bool) $party->tax_exempt);

        return DB::transaction(function () use ($party, $data, $order, $lines, $subtotal, $tax, $total) {
            $order ??= new FinSalesOrder(['company_id' => $party->company_id, 'created_by' => Auth::id(),
                'number' => FinNumberSeries::next($party->company_id, 'sales_order', 'SO-')]);
            $order->fill([
                'party_id' => $party->id, 'order_date' => Carbon::parse($data['order_date'] ?? now()),
                'currency' => $data['currency'] ?? config('finance.base_currency', 'ZAR'),
                'status' => $order->status ?? 'open', 'subtotal' => $subtotal, 'tax_total' => $tax, 'total' => $total,
                'customer_ref' => $data['customer_ref'] ?? null, 'description' => $data['description'] ?? null,
            ])->save();

            $order->lines()->delete();
            foreach ($lines as $l) {
                $order->lines()->create($l + ['qty_delivered' => 0]);
            }

            return $order->fresh('lines');
        });
    }

    public function createFromQuote(FinQuote $quote): FinSalesOrder
    {
        if ($quote->sales_order_id) {
            throw new PostingException('This quote already has a sales order.');
        }
        if (in_array($quote->status, ['declined', 'invoiced'], true)) {
            throw new PostingException("A {$quote->status} quote cannot become an order.");
        }

        return DB::transaction(function () use ($quote) {
            $order = $this->save([
                'party_id' => $quote->party_id, 'currency' => $quote->currency,
                'customer_ref' => $quote->customer_ref, 'description' => $quote->description,
                'lines' => $quote->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description,
                    'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
                ])->all(),
            ]);
            $order->update(['quote_id' => $quote->id]);
            $quote->update(['sales_order_id' => $order->id, 'status' => 'accepted']);

            return $order;
        });
    }

    /** Record a delivery against the order. $deliveries: [order_line_id => qty]. */
    public function deliver(FinSalesOrder $order, array $deliveries, ?string $notes = null): FinDeliveryNote
    {
        if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
            throw new PostingException("A {$order->status} order cannot be delivered.");
        }

        return DB::transaction(function () use ($order, $deliveries, $notes) {
            $dn = FinDeliveryNote::create([
                'company_id' => $order->company_id, 'order_id' => $order->id, 'party_id' => $order->party_id,
                'number' => FinNumberSeries::next($order->company_id, 'delivery_note', 'DN-'),
                'delivery_date' => now(), 'notes' => $notes, 'created_by' => Auth::id(),
            ]);

            foreach ($order->lines as $line) {
                $qty = round((float) ($deliveries[$line->id] ?? 0), 4);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $line->outstandingQty() + 0.0001) {
                    throw new PostingException("Delivering more than ordered on “{$line->description}”.");
                }
                $dn->lines()->create(['order_line_id' => $line->id, 'description' => $line->description, 'quantity' => $qty]);
                $line->update(['qty_delivered' => round((float) $line->qty_delivered + $qty, 4)]);
            }

            $order->update(['status' => $this->deliveryStatus($order->fresh('lines'))]);

            return $dn->fresh('lines');
        });
    }

    /** Invoice the whole order (posts to the GL) and mark it invoiced. */
    public function invoice(FinSalesOrder $order): FinInvoice
    {
        if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
            throw new PostingException("This order is already {$order->status}.");
        }

        return DB::transaction(function () use ($order) {
            $invoice = $this->arap->createInvoice([
                'party_id' => $order->party_id, 'direction' => 'receivable', 'currency' => $order->currency,
                'description' => "From order {$order->number}" . ($order->description ? " — {$order->description}" : ''),
                'lines' => $order->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description,
                    'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
                ])->all(),
            ]);
            $order->update(['invoice_id' => $invoice->id, 'status' => 'invoiced']);

            return $invoice;
        });
    }

    public function cancel(FinSalesOrder $order): FinSalesOrder
    {
        if ($order->status === 'invoiced') {
            throw new PostingException('An invoiced order cannot be cancelled.');
        }
        $order->update(['status' => 'cancelled']);

        return $order;
    }

    private function deliveryStatus(FinSalesOrder $order): string
    {
        $anyDelivered = $order->lines->contains(fn ($l) => (float) $l->qty_delivered > 0);
        $allDelivered = $order->lines->every(fn ($l) => $l->outstandingQty() <= 0.0001);

        return $allDelivered ? 'delivered' : ($anyDelivered ? 'part_delivered' : 'open');
    }

    private function computeLines(array $input, bool $exempt): array
    {
        $lines = [];
        $subtotal = $taxTotal = 0.0;
        foreach ($input as $l) {
            $qty = round((float) ($l['quantity'] ?? 1), 4);
            $price = round((float) ($l['unit_price'] ?? 0), 4);
            $lineTotal = round($qty * $price, 2);
            $rate = $exempt ? 0.0 : round((float) ($l['tax_rate'] ?? 0), 3);
            $tax = round($lineTotal * $rate / 100, 2);
            $lines[] = [
                'account_id' => (int) $l['account_id'], 'item_id' => $l['item_id'] ?? null, 'description' => $l['description'] ?? '',
                'quantity' => $qty, 'unit_price' => $price, 'line_total' => $lineTotal, 'tax_rate' => $rate, 'tax_amount' => $tax,
            ];
            $subtotal += $lineTotal;
            $taxTotal += $tax;
        }
        if (! $lines) {
            throw new PostingException('An order needs at least one line.');
        }

        return [$lines, round($subtotal, 2), round($taxTotal, 2), round($subtotal + $taxTotal, 2)];
    }
}
