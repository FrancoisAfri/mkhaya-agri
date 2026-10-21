<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinDeliveryNote;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinSalesOrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sales orders + delivery notes (quote → SO → delivery → invoice). Orders and
 * delivery notes don't post; invoicing an order posts through the shared engine.
 *
 * An order can be invoiced whole, or delivery by delivery for what the customer accepted — the way
 * produce is sold, where a load is checked on arrival and part of it can be turned away. Lines carry
 * grade, unit and where their price came from; the order carries the ledger dimensions its income
 * lands on and what it came from (a supply contract, a harvest commitment).
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
                'dimensions' => $data['dimensions'] ?? $order->dimensions, 'deliver_on' => $data['deliver_on'] ?? $order->deliver_on,
                'delivery_address' => $data['delivery_address'] ?? $order->delivery_address,
                'source_type' => $data['source_type'] ?? $order->source_type, 'source_id' => $data['source_id'] ?? $order->source_id,
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
                'dimensions' => $quote->dimensions, 'delivery_address' => $quote->delivery_address,
                'lines' => $quote->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description,
                    'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
                    'unit' => $l->unit, 'grade' => $l->grade, 'price_basis' => $l->price_basis,
                ])->all(),
            ]);
            $order->update(['quote_id' => $quote->id]);
            $quote->update(['sales_order_id' => $order->id, 'status' => 'accepted']);

            return $order;
        });
    }

    /**
     * Record a delivery against the order. $deliveries: [order_line_id => qty sent]. $outcomes, when
     * the customer has said, is [order_line_id => ['accepted' => qty, 'rejected' => qty]]; what is
     * neither accepted nor rejected never arrived.
     */
    public function deliver(FinSalesOrder $order, array $deliveries, ?string $notes = null, array $outcomes = []): FinDeliveryNote
    {
        if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
            throw new PostingException("A {$order->status} order cannot be delivered.");
        }

        return DB::transaction(function () use ($order, $deliveries, $notes, $outcomes) {
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
                $o = $outcomes[$line->id] ?? null;
                $acc = $o !== null ? round((float) ($o['accepted'] ?? 0), 4) : null;
                $rej = $o !== null ? round((float) ($o['rejected'] ?? 0), 4) : null;
                if ($o !== null && ($acc < 0 || $rej < 0 || $acc + $rej > $qty + 0.0001)) {
                    throw new PostingException("Accepted and rejected on “{$line->description}” come to more than was sent.");
                }
                $dn->lines()->create(['order_line_id' => $line->id, 'description' => $line->description, 'quantity' => $qty,
                    'qty_accepted' => $acc, 'qty_rejected' => $rej]);
                $line->update(['qty_delivered' => round((float) $line->qty_delivered + $qty, 4),
                    'qty_accepted' => round((float) $line->qty_accepted + ($acc ?? $qty), 4), 'qty_rejected' => round((float) $line->qty_rejected + ($rej ?? 0), 4)]);
            }

            $order->update(['status' => $this->deliveryStatus($order->fresh('lines'))]);

            return $dn->fresh('lines');
        });
    }

    /**
     * What the customer accepted of a delivery already recorded — the warehouse writes the note when
     * the goods leave, and the customer says on arrival. $outcomes: [order_line_id => ['accepted' =>
     * qty, 'rejected' => qty]]. A note not yet invoiced takes its outcomes once.
     */
    public function recordOutcomes(FinDeliveryNote $dn, array $outcomes): FinDeliveryNote
    {
        if ($dn->invoice_id) {
            throw new PostingException("Delivery {$dn->number} is invoiced; its outcome can no longer change.");
        }

        return DB::transaction(function () use ($dn, $outcomes) {
            foreach ($dn->lines()->get() as $dl) {
                $o = $outcomes[$dl->order_line_id] ?? null;
                if ($o === null) {
                    continue;
                }
                if ($dl->qty_accepted !== null || $dl->qty_rejected !== null) {
                    throw new PostingException("The outcome of “{$dl->description}” on {$dn->number} is already recorded.");
                }
                $sent = (float) $dl->quantity;
                $acc = round((float) ($o['accepted'] ?? 0), 4);
                $rej = round((float) ($o['rejected'] ?? 0), 4);
                if ($acc < 0 || $rej < 0 || $acc + $rej > $sent + 0.0001) {
                    throw new PostingException("Accepted and rejected on “{$dl->description}” come to more than was sent.");
                }
                $dl->update(['qty_accepted' => $acc, 'qty_rejected' => $rej]);
                // The order counted the whole delivery as accepted when it was sent.
                $line = FinSalesOrderLine::query()->findOrFail($dl->order_line_id);
                $line->update(['qty_accepted' => round(max(0, (float) $line->qty_accepted - $sent + $acc), 4),
                    'qty_rejected' => round((float) $line->qty_rejected + $rej, 4)]);
            }

            return $dn->fresh('lines');
        });
    }

    /**
     * Invoice what one delivery's customer accepted (posts to the GL), at the order's prices and on
     * its dimensions. $options: invoice_date, description, party_ref, account_id (overrides each
     * line's), tax_rate, relieve_stock (false when the stock already left on a dispatch).
     */
    public function invoiceDelivery(FinDeliveryNote $dn, array $options = []): ?FinInvoice
    {
        if ($dn->invoice_id) {
            return FinInvoice::find($dn->invoice_id);
        }
        $order = FinSalesOrder::query()->with('lines')->findOrFail($dn->order_id);
        if ($order->status === 'cancelled') {
            throw new PostingException('A cancelled order is not invoiced.');
        }
        $lines = $order->lines->keyBy('id');
        $invLines = $dn->lines()->get()->map(function ($dl) use ($lines, $options, $dn) {
            $l = $lines[$dl->order_line_id];

            return ['order_line' => $l, 'quantity' => (float) ($dl->qty_accepted ?? $dl->quantity),
                'line' => ['account_id' => (int) ($options['account_id'] ?? $l->account_id), 'item_id' => $l->item_id,
                    'description' => trim("{$l->description} {$l->grade}")." ({$dn->number})", 'quantity' => (float) ($dl->qty_accepted ?? $dl->quantity),
                    'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) ($options['tax_rate'] ?? $l->tax_rate)]];
        })->filter(fn ($x) => $x['quantity'] > 0)->values();
        if ($invLines->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($order, $dn, $invLines, $options) {
            $invoice = $this->arap->createInvoice([
                'party_id' => $order->party_id, 'direction' => 'receivable', 'currency' => $order->currency,
                'invoice_date' => $options['invoice_date'] ?? null, 'party_ref' => $options['party_ref'] ?? $order->customer_ref,
                'description' => $options['description'] ?? "{$order->number} / {$dn->number}",
                'lines' => $invLines->pluck('line')->all(), 'dimensions' => $order->dimensions,
                'relieve_stock' => $options['relieve_stock'] ?? true,
            ]);
            foreach ($invLines as $x) {
                $x['order_line']->update(['qty_invoiced' => round((float) $x['order_line']->qty_invoiced + $x['quantity'], 4)]);
            }
            $dn->update(['invoice_id' => $invoice->id]);
            $order->refresh()->load('lines');
            $settled = $order->lines->every(fn ($l) => (float) $l->qty_invoiced + (float) $l->qty_rejected + 0.0001 >= (float) $l->qty_delivered);
            if ($this->deliveryStatus($order) === 'delivered' && $settled) {
                $order->update(['status' => 'invoiced', 'invoice_id' => $invoice->id]);
            }

            return $invoice;
        });
    }

    /** Invoice the whole order (posts to the GL) and mark it invoiced. */
    public function invoice(FinSalesOrder $order): FinInvoice
    {
        if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
            throw new PostingException("This order is already {$order->status}.");
        }
        if ($order->lines->contains(fn ($l) => (float) $l->qty_invoiced > 0)) {
            throw new PostingException('This order is being invoiced delivery by delivery; invoice the remaining deliveries.');
        }

        return DB::transaction(function () use ($order) {
            $invoice = $this->arap->createInvoice([
                'party_id' => $order->party_id, 'direction' => 'receivable', 'currency' => $order->currency,
                'description' => "From order {$order->number}" . ($order->description ? " — {$order->description}" : ''),
                'lines' => $order->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description,
                    'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
                ])->all(),
                'dimensions' => $order->dimensions,
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
                'unit' => $l['unit'] ?? null, 'grade' => $l['grade'] ?? null, 'price_basis' => $l['price_basis'] ?? null,
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
