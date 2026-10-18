<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Quotations. Non-posting; on acceptance a quote becomes a receivable invoice
 * through the shared ArApService (which posts to the GL).
 */
class QuoteService
{
    public function __construct(private ArApService $arap)
    {
    }

    public function createQuote(array $data): FinQuote
    {
        $party = FinParty::findOrFail($data['party_id']);
        [$lines, $subtotal, $taxTotal] = $this->computeLines($data['lines'], (bool) $party->tax_exempt);
        [$pct, $discountAmount, $taxAfter, $total] = $this->applyDiscount($subtotal, $taxTotal, (float) ($data['discount_percent'] ?? 0));

        return DB::transaction(function () use ($party, $data, $lines, $subtotal, $pct, $discountAmount, $taxAfter, $total) {
            $quoteDate = Carbon::parse($data['quote_date'] ?? now());
            $quote = FinQuote::create([
                'company_id'  => $party->company_id, 'party_id' => $party->id,
                'number'      => FinNumberSeries::next($party->company_id, 'quote', 'QUO-'),
                'quote_date'  => $quoteDate,
                'valid_until' => isset($data['valid_until']) ? Carbon::parse($data['valid_until']) : (clone $quoteDate)->addDays(30),
                'currency'    => $data['currency'] ?? config('finance.base_currency', 'ZAR'),
                'status'      => 'draft', 'subtotal' => $subtotal, 'tax_total' => $taxAfter, 'total' => $total,
                'discount_percent' => $pct, 'discount_amount' => $discountAmount,
                'description' => $data['description'] ?? null, 'customer_ref' => $data['customer_ref'] ?? null,
                // Defaults from the customer, so it is typed once rather than on every quote.
                'vendor_number' => $data['vendor_number'] ?? $party->vendor_number,
                'sales_rep' => $data['sales_rep'] ?? null, 'delivery_address' => $data['delivery_address'] ?? null,
                'postal_address' => $data['postal_address'] ?? null,
                'crm_deal_id' => $data['crm_deal_id'] ?? null, 'created_by' => Auth::id(),
            ]);
            $quote->lines()->createMany($lines);

            return $quote->fresh('lines');
        });
    }

    /** Document-level discount: reduces the subtotal and scales tax proportionally. */
    private function applyDiscount(float $subtotal, float $taxTotal, float $pct): array
    {
        $pct = round(max(0, min(100, $pct)), 3);
        $discountAmount = round($subtotal * $pct / 100, 2);
        $discounted = round($subtotal - $discountAmount, 2);
        $factor = $subtotal > 0 ? $discounted / $subtotal : 1;
        $taxAfter = round($taxTotal * $factor, 2);

        return [$pct, $discountAmount, $taxAfter, round($discounted + $taxAfter, 2)];
    }

    public function updateQuote(FinQuote $quote, array $data): FinQuote
    {
        if (! $quote->isEditable()) {
            throw new PostingException("A {$quote->status} quote can no longer be edited.");
        }
        [$lines, $subtotal, $taxTotal] = $this->computeLines($data['lines'], (bool) $quote->party->tax_exempt);
        [$pct, $discountAmount, $taxAfter, $total] = $this->applyDiscount($subtotal, $taxTotal, (float) ($data['discount_percent'] ?? 0));

        return DB::transaction(function () use ($quote, $data, $lines, $subtotal, $pct, $discountAmount, $taxAfter, $total) {
            $quote->update([
                'valid_until' => isset($data['valid_until']) ? Carbon::parse($data['valid_until']) : $quote->valid_until,
                'description' => $data['description'] ?? $quote->description,
                'customer_ref' => $data['customer_ref'] ?? $quote->customer_ref,
                'vendor_number' => $data['vendor_number'] ?? $quote->vendor_number,
                'sales_rep' => $data['sales_rep'] ?? $quote->sales_rep,
                'delivery_address' => $data['delivery_address'] ?? $quote->delivery_address,
                'postal_address' => $data['postal_address'] ?? $quote->postal_address,
                'crm_deal_id' => $data['crm_deal_id'] ?? $quote->crm_deal_id,
                'subtotal' => $subtotal, 'tax_total' => $taxAfter, 'total' => $total,
                'discount_percent' => $pct, 'discount_amount' => $discountAmount,

                /*
                 * An amended quote goes back to draft if it had been accepted.
                 *
                 * The customer agreed to the figures they were shown. Leaving the acceptance
                 * attached to different figures would record an agreement that was never given —
                 * and it is the version somebody would point at later.
                 */
                'status' => in_array($quote->status, FinQuote::AGREED, true) ? 'draft' : $quote->status,
            ]);
            $quote->lines()->delete();
            $quote->lines()->createMany($lines);

            return $quote->fresh('lines');
        });
    }

    public function changeStatus(FinQuote $quote, string $status): FinQuote
    {
        $allowed = ['draft', 'sent', 'accepted', 'declined', 'expired'];
        if (! in_array($status, $allowed, true)) {
            throw new PostingException("Invalid quote status {$status}.");
        }
        if ($quote->status === 'invoiced') {
            throw new PostingException('An invoiced quote cannot change status.');
        }
        $quote->update(['status' => $status]);

        return $quote->fresh();
    }

    /** Convert an accepted quote into a receivable invoice (idempotent). */
    public function convertToInvoice(FinQuote $quote): FinInvoice
    {
        if ($quote->invoice_id) {
            return $quote->invoice;
        }
        if ($quote->status === 'declined') {
            throw new PostingException('A declined quote cannot be invoiced.');
        }

        // Net the document discount into each line so the invoice matches the quote.
        $factor = 1 - ((float) $quote->discount_percent) / 100;

        return DB::transaction(function () use ($quote, $factor) {
            $invoice = $this->arap->createInvoice([
                'party_id'  => $quote->party_id, 'direction' => 'receivable',
                'currency'  => $quote->currency, 'description' => $quote->description ?: "From quote {$quote->number}",
                'lines'     => $quote->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description, 'note' => $l->note,
                    'quantity' => (float) $l->quantity, 'unit_price' => round((float) $l->unit_price * $factor, 4),
                    'tax_rate' => (float) $l->tax_rate, 'tax_code' => $l->tax_code,
                ])->all(),
            ]);

            $quote->update(['status' => 'invoiced', 'invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /** @return array{0: array, 1: float, 2: float, 3: float} [lines, subtotal, tax, total] */
    private function computeLines(array $input, bool $exempt = false): array
    {
        $lines = [];
        $subtotal = $taxTotal = 0.0;
        foreach ($input as $l) {
            $qty = round((float) ($l['quantity'] ?? 1), 4);
            $price = round((float) ($l['unit_price'] ?? 0), 4);
            $lineTotal = round($qty * $price, 2);
            $rate = $exempt ? 0.0 : round((float) ($l['tax_rate'] ?? 0), 3);
            $taxAmount = round($lineTotal * $rate / 100, 2);

            $lines[] = [
                'account_id' => (int) $l['account_id'], 'item_id' => $l['item_id'] ?? null,
                'description' => $l['description'] ?? '', 'note' => $l['note'] ?? null,
                'quantity' => $qty, 'unit_price' => $price, 'line_total' => $lineTotal,
                'tax_code' => $l['tax_code'] ?? null, 'tax_rate' => $rate, 'tax_amount' => $taxAmount,
            ];
            $subtotal += $lineTotal;
            $taxTotal += $taxAmount;
        }

        if (! $lines) {
            throw new PostingException('A quote needs at least one line.');
        }

        return [$lines, round($subtotal, 2), round($taxTotal, 2), round($subtotal + $taxTotal, 2)];
    }
}
