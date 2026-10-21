<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Order-to-cash and procure-to-pay, symmetric. Every invoice and payment posts
 * to the GL through the shared Ledger — nothing here writes journal lines
 * directly, so the AR/AP control accounts always equal the open sub-ledger.
 */
class ArApService
{
    public function __construct(private Ledger $ledger, private InventoryService $inventory)
    {
    }

    private function acc(string $key): string
    {
        return (string) config("finance.$key");
    }

    /**
     * Create and post an invoice (receivable) or bill (payable).
     *
     * @param array{party_id:int, direction:string, invoice_date?:string, due_date?:string, currency?:string, party_ref?:string, description?:string, lines:array} $data
     */
    public function createInvoice(array $data): FinInvoice
    {
        $party = FinParty::findOrFail($data['party_id']);
        $direction = $data['direction'];
        if (! in_array($direction, ['receivable', 'payable'], true)) {
            throw new PostingException('Invoice direction must be receivable or payable.');
        }

        $invoiceDate = Carbon::parse($data['invoice_date'] ?? now());
        $dueDate = isset($data['due_date'])
            ? Carbon::parse($data['due_date'])
            : (clone $invoiceDate)->addDays((int) $party->credit_terms_days);

        [$lines, $subtotal, $taxTotal, $discountTotal, $total] = $this->priceLines($data['lines'], $party);

        return DB::transaction(function () use ($party, $direction, $invoiceDate, $dueDate, $data, $lines, $subtotal, $taxTotal, $total, $discountTotal) {
            $prefix = $direction === 'receivable' ? 'INV-' : 'BILL-';
            $invoice = FinInvoice::create([
                'company_id' => $party->company_id, 'party_id' => $party->id, 'direction' => $direction,
                'number' => FinNumberSeries::next($party->company_id, "{$direction}_invoice", $prefix),
                'party_ref' => $data['party_ref'] ?? null,
                /*
                 * The supplier account this customer files us under, stamped onto the
                 * document rather than read back from the party later. It is what their
                 * accounts-payable clerk matches on, so it has to say what it said on the day
                 * — a customer who is later given a new number must not silently rewrite the
                 * invoices already sitting in their system under the old one.
                 */
                'vendor_number' => $data['vendor_number'] ?? $party->vendor_number,
                'invoice_date' => $invoiceDate, 'due_date' => $dueDate,
                'currency' => $data['currency'] ?? config('finance.base_currency', 'ZAR'),
                'status' => 'open', 'subtotal' => $subtotal, 'discount_total' => $discountTotal,
                'tax_total' => $taxTotal, 'total' => $total,
                'amount_paid' => 0, 'description' => $data['description'] ?? null, 'created_by' => Auth::id(),
                'terms' => $data['terms'] ?? null, 'template_id' => $data['template_id'] ?? null,
                'dimensions' => $data['dimensions'] ?? null,
            ]);
            $invoice->lines()->createMany($lines);

            $journal = $this->postInvoice($invoice, $lines);
            $invoice->update(['journal_id' => $journal->id]);
            $fresh = $invoice->fresh('lines');

            // Relieve stock + post cost-of-sales for any stock-tracked lines (skipped for
            // migration imports, which pass relieve_stock=false to avoid double-counting).
            if ($direction === 'receivable' && ($data['relieve_stock'] ?? true)) {
                $this->inventory->relieveForSale($fresh);
            }

            return $fresh;
        });
    }

    /**
     * Price a set of invoice lines, once, for both raising and amending.
     *
     * Extracted rather than copied: an amendment that priced VAT or a discount even slightly
     * differently from the original would produce an invoice whose total no longer matched the
     * ledger entry behind it, and the difference would surface as an unexplained balance on the
     * control account months later.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: float, 3: float, 4: float}
     */
    private function priceLines(array $input, FinParty $party): array
    {
        // A tax-exempt party is charged no VAT.
        $exempt = (bool) $party->tax_exempt;
        $lines = [];
        $subtotal = $taxTotal = 0.0;
        $discountTotal = 0.0;

        foreach ($input as $l) {
            $qty = round((float) ($l['quantity'] ?? 1), 4);
            $price = round((float) ($l['unit_price'] ?? 0), 4);
            $gross = round($qty * $price, 2);

            // Discount comes off before tax. VAT is due on the consideration actually charged, so
            // taxing the gross would collect tax on money the customer never pays.
            $discountRate = max(0.0, min(100.0, round((float) ($l['discount_rate'] ?? 0), 3)));
            $discount = round($gross * $discountRate / 100, 2);
            $lineTotal = round($gross - $discount, 2);

            $rate = $exempt ? 0.0 : round((float) ($l['tax_rate'] ?? 0), 3);
            $taxAmount = round($lineTotal * $rate / 100, 2);

            $lines[] = [
                'account_id' => (int) $l['account_id'], 'item_id' => $l['item_id'] ?? null,
                'description' => $l['description'] ?? '', 'note' => $l['note'] ?? null,
                'quantity' => $qty, 'unit_price' => $price,
                'discount_rate' => $discountRate, 'discount_amount' => $discount,
                'line_total' => $lineTotal,
                'tax_code' => $l['tax_code'] ?? null, 'tax_rate' => $rate, 'tax_amount' => $taxAmount,
            ];
            $subtotal += $lineTotal;
            $taxTotal += $taxAmount;
            $discountTotal += $discount;
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $discountTotal = round($discountTotal, 2);
        $total = round($subtotal + $taxTotal, 2);

        if ($total <= 0) {
            throw new PostingException('An invoice must have a positive total.');
        }

        return [$lines, $subtotal, $taxTotal, $discountTotal, $total];
    }

    /**
     * Amend an invoice that has not been settled.
     *
     * An invoice is not a draft: it posts to the ledger the moment it is raised, so "editing" one
     * is not a field update. The old journal is reversed and a fresh one posted, which is what
     * leaves an auditor a trail showing what the invoice said before, what it says now, and that
     * the two reconcile. Overwriting the row and leaving the original journal in place would put
     * the control account permanently out of step with the document that justifies it.
     *
     * ── What this refuses, and why ───────────────────────────────────────────
     *
     * Money already received against it. Reversing a journal that a receipt has been allocated to
     * would strand the payment against an amount that no longer exists; the receipt comes off
     * first, exactly as voiding requires.
     *
     * Stock already relieved. Relief is one-way here — there is no inverse that rebuilds the cost
     * layers it consumed — so re-posting an amended quantity would leave the stock ledger saying
     * something the inventory does not. A credit note is the honest instrument for that.
     *
     * The number is kept. An amended invoice is the same document, and a gap in an invoice
     * sequence is a question from SARS rather than a tidy-up.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateInvoice(FinInvoice $invoice, array $data): FinInvoice
    {
        if ($invoice->status === 'void') {
            throw new PostingException('A voided invoice cannot be amended.');
        }

        if (in_array($invoice->doc_type, ['credit_note', 'debit_note', 'writeoff'], true)) {
            throw new PostingException('A credit or debit note cannot be amended — issue another one.');
        }

        if ((float) $invoice->amount_paid > 0 || $this->hasAllocations($invoice)) {
            throw new PostingException(
                'This invoice has money allocated against it — reverse the receipt first, or issue a credit note.'
            );
        }

        if ($this->hasRelievedStock($invoice)) {
            throw new PostingException(
                'This invoice has already taken stock out of the storeroom, and that cannot be put '
                .'back at the cost it left at. Issue a credit note instead.'
            );
        }

        $party = $invoice->party_id === ($data['party_id'] ?? $invoice->party_id)
            ? $invoice->party
            : FinParty::findOrFail($data['party_id']);

        [$lines, $subtotal, $taxTotal, $discountTotal, $total] =
            $this->priceLines($data['lines'], $party);

        $invoiceDate = isset($data['invoice_date'])
            ? Carbon::parse($data['invoice_date'])
            : $invoice->invoice_date;

        $dueDate = isset($data['due_date'])
            ? Carbon::parse($data['due_date'])
            : (clone $invoiceDate)->addDays((int) $party->credit_terms_days);

        return DB::transaction(function () use (
            $invoice, $party, $data, $lines, $subtotal, $taxTotal, $discountTotal, $total,
            $invoiceDate, $dueDate
        ) {
            // Out with the old entry before the new one, so the two never both stand.
            if ($invoice->journal_id) {
                $this->ledger->reverse(
                    $invoice->journal->fresh('lines'),
                    "Amend invoice {$invoice->number}"
                );
            }

            $invoice->update([
                'party_id' => $party->id,
                'party_ref' => $data['party_ref'] ?? $invoice->party_ref,
                'vendor_number' => $data['vendor_number'] ?? $invoice->vendor_number,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'description' => $data['description'] ?? $invoice->description,
                'terms' => $data['terms'] ?? $invoice->terms,
                'template_id' => $data['template_id'] ?? $invoice->template_id,
            ]);

            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);

            $journal = $this->postInvoice($invoice->fresh('lines'), $lines);
            $invoice->update(['journal_id' => $journal->id]);

            return $invoice->fresh('lines');
        });
    }

    /** Has anything been allocated against this invoice? */
    private function hasAllocations(FinInvoice $invoice): bool
    {
        return DB::table('fin_payment_allocations')
            ->where('invoice_id', $invoice->id)
            ->exists();
    }

    /**
     * Did this invoice take stock out of the storeroom?
     *
     * Read from the movements it wrote rather than inferred from the lines: a line may name a
     * stock item that was not tracked at the time, and the question that matters is what actually
     * left, not what might have.
     */
    private function hasRelievedStock(FinInvoice $invoice): bool
    {
        return DB::table('fin_stock_movements')
            ->where('reference', "invoice:{$invoice->id}")
            ->exists();
    }

    /**
     * The idempotency key for this posting of the invoice.
     *
     * Posting is idempotent on this key, which is what stops a retried request raising the same
     * entry twice — and it is also what made the first version of amendment silently wrong. An
     * amendment reverses the original entry and posts a replacement; under a key fixed to the
     * invoice id, the replacement was handed back the journal that had just been reversed, so the
     * invoice ended up pointing at a cancelled entry and the receivables control fell to nil. The
     * tests caught it; nothing on screen would have.
     *
     * So the key carries which posting this is. The first keeps the original form, because changing
     * it would make every invoice already in the ledger look unposted to a retry.
     */
    private function postingKeyFor(FinInvoice $invoice): string
    {
        $base = "arap_invoice:{$invoice->id}";

        $alreadyPosted = FinJournal::withoutGlobalScopes()
            ->where('idempotency_key', 'like', $base.'%')
            ->count();

        return $alreadyPosted === 0 ? $base : "{$base}:r{$alreadyPosted}";
    }

    private function postInvoice(FinInvoice $invoice, array $lines): \App\Modules\Finance\Accounting\Models\FinJournal
    {
        $debits = $credits = [];

        if ($invoice->direction === 'receivable') {
            // Dr AR control; Cr income per line; Cr VAT output.
            $debits[] = ['account' => $this->acc('ar_control'), 'amount' => (float) $invoice->total, 'narration' => "Invoice {$invoice->number}"];
            foreach ($lines as $l) {
                $credits[] = ['account' => $l['account_id'], 'amount' => $l['line_total'], 'narration' => $l['description']]
                    + ($invoice->dimensions ? ['dimensions' => $invoice->dimensions] : []);
            }
            if ($invoice->tax_total > 0) {
                $credits[] = ['account' => $this->acc('vat_output'), 'amount' => (float) $invoice->tax_total, 'narration' => 'VAT'];
            }
        } else {
            // Dr expense per line; Dr VAT input; Cr AP control.
            foreach ($lines as $l) {
                $debits[] = ['account' => $l['account_id'], 'amount' => $l['line_total'], 'narration' => $l['description']];
            }
            if ($invoice->tax_total > 0) {
                $debits[] = ['account' => $this->acc('vat_input'), 'amount' => (float) $invoice->tax_total, 'narration' => 'VAT'];
            }
            $credits[] = ['account' => $this->acc('ap_control'), 'amount' => (float) $invoice->total, 'narration' => "Bill {$invoice->number}"];
        }

        return $this->ledger->post(new FinancialEvent(
            sourceModule: $invoice->direction === 'receivable' ? 'ar' : 'ap',
            eventType: $invoice->direction === 'receivable' ? 'ar.invoice.raised' : 'ap.bill.received',
            debits: $debits, credits: $credits,
            eventDate: $invoice->invoice_date, companyId: $invoice->company_id, currency: $invoice->currency,
            sourceRef: "invoice:{$invoice->id}", description: "{$invoice->number} · {$invoice->party->name}",
            idempotencyKey: $this->postingKeyFor($invoice),
        ));
    }

    /**
     * Record a receipt (from customer) or payment (to supplier), fully allocated
     * across invoices, and post it to the GL.
     *
     * @param array{party_id:int, direction:string, payment_date?:string, bank_account_id?:string, reference?:string, allocations:array<int,array{invoice_id:int, amount:float}>} $data
     */
    public function recordPayment(array $data): FinPayment
    {
        $party = FinParty::findOrFail($data['party_id']);
        $direction = $data['direction']; // receipt | payment
        if (! in_array($direction, ['receipt', 'payment'], true)) {
            throw new PostingException('Payment direction must be receipt or payment.');
        }

        $allocations = $data['allocations'] ?? [];
        if (empty($allocations)) {
            throw new PostingException('At least one invoice allocation is required.');
        }

        $amount = round(array_sum(array_map(fn ($a) => (float) $a['amount'], $allocations)), 2);
        if ($amount <= 0) {
            throw new PostingException('Payment amount must be positive.');
        }

        $bankCode = $data['bank_account_id'] ?? $this->acc('default_bank');
        $payDate = Carbon::parse($data['payment_date'] ?? now());
        $wantDirection = $direction === 'receipt' ? 'receivable' : 'payable';

        return DB::transaction(function () use ($party, $direction, $wantDirection, $allocations, $amount, $bankCode, $payDate, $data) {
            $prefix = $direction === 'receipt' ? 'RCT-' : 'PMT-';
            $payment = FinPayment::create([
                'company_id' => $party->company_id, 'party_id' => $party->id, 'direction' => $direction,
                'number' => FinNumberSeries::next($party->company_id, $direction, $prefix),
                'payment_date' => $payDate, 'currency' => config('finance.base_currency', 'ZAR'),
                'amount' => $amount, 'bank_account_id' => $this->resolveBankId($bankCode),
                'reference' => $data['reference'] ?? null, 'created_by' => Auth::id(),
            ]);

            foreach ($allocations as $a) {
                $invoice = FinInvoice::where('id', $a['invoice_id'])->where('party_id', $party->id)
                    ->where('direction', $wantDirection)->lockForUpdate()->firstOrFail();
                $alloc = round((float) $a['amount'], 2);
                if ($alloc <= 0 || $alloc > $invoice->outstanding() + 0.001) {
                    throw new PostingException("Allocation of {$alloc} exceeds outstanding on {$invoice->number}.");
                }
                $payment->allocations()->create(['invoice_id' => $invoice->id, 'amount' => $alloc]);

                $paid = round((float) $invoice->amount_paid + $alloc, 2);
                $invoice->update([
                    'amount_paid' => $paid,
                    'status' => $paid >= (float) $invoice->total - 0.001 ? 'paid' : 'part_paid',
                ]);
            }

            $journal = $this->postPayment($payment, $bankCode);
            $payment->update(['journal_id' => $journal->id]);

            return $payment->fresh('allocations');
        });
    }

    private function postPayment(FinPayment $payment, string $bankCode): \App\Modules\Finance\Accounting\Models\FinJournal
    {
        if ($payment->direction === 'receipt') {
            // Dr Bank; Cr AR control.
            $debits = [['account' => $bankCode, 'amount' => (float) $payment->amount, 'narration' => "Receipt {$payment->number}"]];
            $credits = [['account' => $this->acc('ar_control'), 'amount' => (float) $payment->amount, 'narration' => $payment->party->name]];
        } else {
            // Dr AP control; Cr Bank.
            $debits = [['account' => $this->acc('ap_control'), 'amount' => (float) $payment->amount, 'narration' => $payment->party->name]];
            $credits = [['account' => $bankCode, 'amount' => (float) $payment->amount, 'narration' => "Payment {$payment->number}"]];
        }

        return $this->ledger->post(new FinancialEvent(
            sourceModule: $payment->direction === 'receipt' ? 'ar' : 'ap',
            eventType: $payment->direction === 'receipt' ? 'ar.receipt' : 'ap.payment',
            debits: $debits, credits: $credits,
            eventDate: $payment->payment_date, companyId: $payment->company_id, currency: $payment->currency,
            sourceRef: "payment:{$payment->id}", description: "{$payment->number} · {$payment->party->name}",
            idempotencyKey: "arap_payment:{$payment->id}",
        ));
    }

    /**
     * Credit note against a sales invoice: reverses income + VAT, credits the AR
     * control, and reduces the invoice balance. Tax is split at the invoice's ratio.
     */
    public function createCreditNote(FinInvoice $invoice, float $amount, string $reason): FinInvoice
    {
        if ($invoice->direction !== 'receivable' || $invoice->doc_type !== 'invoice') {
            throw new PostingException('Credit notes apply to sales invoices only.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $invoice->outstanding() + 0.001) {
            throw new PostingException('Credit amount must be between 0 and the invoice outstanding (' . number_format($invoice->outstanding(), 2) . ').');
        }

        $invoice->loadMissing('lines');
        $incomeAccountId = $invoice->lines->first()?->account_id;
        if (! $incomeAccountId) {
            throw new PostingException('Cannot determine the income account to credit.');
        }

        $taxRatio = (float) $invoice->total > 0 ? (float) $invoice->tax_total / (float) $invoice->total : 0;
        $tax = round($amount * $taxRatio, 2);
        $net = round($amount - $tax, 2);

        return DB::transaction(function () use ($invoice, $amount, $net, $tax, $reason, $incomeAccountId) {
            $cn = FinInvoice::create([
                'company_id' => $invoice->company_id, 'party_id' => $invoice->party_id,
                'direction' => 'receivable', 'doc_type' => 'credit_note', 'applies_to_invoice_id' => $invoice->id,
                'number' => FinNumberSeries::next($invoice->company_id, 'credit_note', 'CN-'),
                'invoice_date' => now(), 'due_date' => now(), 'currency' => $invoice->currency, 'status' => 'paid',
                'subtotal' => $net, 'tax_total' => $tax, 'total' => $amount, 'amount_paid' => $amount,
                'description' => $reason, 'created_by' => Auth::id(),
            ]);

            $debits = [['account' => $incomeAccountId, 'amount' => $net, 'narration' => "Credit note {$cn->number}"]
                + ($invoice->dimensions ? ['dimensions' => $invoice->dimensions] : [])];
            if ($tax > 0) {
                $debits[] = ['account' => $this->acc('vat_output'), 'amount' => $tax, 'narration' => 'VAT'];
            }
            $credits = [['account' => $this->acc('ar_control'), 'amount' => $amount, 'narration' => "Credit note {$cn->number}"]];

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'ar', eventType: 'ar.credit_note', debits: $debits, credits: $credits,
                eventDate: now(), companyId: $invoice->company_id, currency: $invoice->currency,
                sourceRef: "credit_note:{$cn->id}", description: "{$cn->number} · {$invoice->party->name}",
                idempotencyKey: "arap_creditnote:{$cn->id}",
            ));
            $cn->update(['journal_id' => $journal->id]);

            $paid = round((float) $invoice->amount_paid + $amount, 2);
            $invoice->update([
                'amount_paid' => $paid,
                'status' => $paid >= (float) $invoice->total - 0.001 ? 'paid' : 'part_paid',
            ]);

            return $cn;
        });
    }

    /**
     * Bring in a historical customer credit note during a data migration. The source export
     * rarely says which invoice it applied to (and that invoice is usually already settled),
     * so — unlike createCreditNote(), which requires an open invoice — this posts as a
     * standalone unapplied credit on the customer account: the reverse of an invoice,
     * Dr income (+ VAT output), Cr AR control. Amounts are taken as positive magnitudes.
     *
     * @param array{party_id:int, credit_date?:string, party_ref?:string, description?:string, lines:array<int,array{account_id:int, description?:string, quantity?:float, unit_price?:float, tax_rate?:float}>} $data
     */
    public function importCreditNote(array $data): FinInvoice
    {
        $party = FinParty::findOrFail($data['party_id']);
        $date = Carbon::parse($data['credit_date'] ?? now());
        $exempt = (bool) $party->tax_exempt;

        $lines = [];
        $subtotal = $taxTotal = 0.0;
        foreach ($data['lines'] as $l) {
            $qty = round((float) ($l['quantity'] ?? 1), 4);
            $price = round(abs((float) ($l['unit_price'] ?? 0)), 4); // credit held as a positive magnitude
            $lineTotal = round($qty * $price, 2);
            $rate = $exempt ? 0.0 : round((float) ($l['tax_rate'] ?? 0), 3);
            $taxAmount = round($lineTotal * $rate / 100, 2);
            $lines[] = [
                'account_id' => (int) $l['account_id'], 'item_id' => null,
                'description' => $l['description'] ?? '', 'note' => null,
                'quantity' => $qty, 'unit_price' => $price, 'line_total' => $lineTotal,
                'tax_code' => null, 'tax_rate' => $rate, 'tax_amount' => $taxAmount,
            ];
            $subtotal += $lineTotal;
            $taxTotal += $taxAmount;
        }
        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $total = round($subtotal + $taxTotal, 2);
        if ($total <= 0) {
            throw new PostingException('A credit note must have a non-zero amount.');
        }
        $incomeAccountId = $lines[0]['account_id'];

        return DB::transaction(function () use ($party, $date, $data, $lines, $subtotal, $taxTotal, $total, $incomeAccountId) {
            $cn = FinInvoice::create([
                'company_id' => $party->company_id, 'party_id' => $party->id,
                'direction' => 'receivable', 'doc_type' => 'credit_note',
                'number' => FinNumberSeries::next($party->company_id, 'credit_note', 'CN-'),
                'party_ref' => $data['party_ref'] ?? null,
                'invoice_date' => $date, 'due_date' => $date,
                'currency' => config('finance.base_currency', 'ZAR'),
                // Zero, and stated rather than inherited: a credit note is raised for a gross
                // amount and this method computes no per-line discount. It referenced an
                // undefined $discountTotal, which the closure never imported either — so every
                // credit note threw "Undefined variable" and none could be issued at all.
                'status' => 'open', 'subtotal' => $subtotal, 'discount_total' => 0,
                'tax_total' => $taxTotal, 'total' => $total,
                'amount_paid' => 0, 'description' => $data['description'] ?? 'Imported credit note',
                'created_by' => Auth::id(),
            ]);
            $cn->lines()->createMany($lines);

            $debits = [['account' => $incomeAccountId, 'amount' => $subtotal, 'narration' => "Credit note {$cn->number}"]];
            if ($taxTotal > 0) {
                $debits[] = ['account' => $this->acc('vat_output'), 'amount' => $taxTotal, 'narration' => 'VAT'];
            }
            $credits = [['account' => $this->acc('ar_control'), 'amount' => $total, 'narration' => "Credit note {$cn->number}"]];

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'ar', eventType: 'ar.credit_note', debits: $debits, credits: $credits,
                eventDate: $date, companyId: $party->company_id, currency: $cn->currency,
                sourceRef: "credit_note:{$cn->id}", description: "{$cn->number} · {$party->name}",
                idempotencyKey: "arap_creditnote:{$cn->id}",
            ));
            $cn->update(['journal_id' => $journal->id]);

            return $cn->fresh('lines');
        });
    }

    /**
     * Write off (part of) a sales invoice as a bad debt: Dr Bad Debts + reclaim
     * VAT, Cr AR control, and settle the invoice. Recorded as a linked writeoff
     * document so the statement/ageing stay consistent with the GL.
     */
    public function writeOff(FinInvoice $invoice, float $amount, string $reason): FinInvoice
    {
        if ($invoice->direction !== 'receivable' || $invoice->doc_type !== 'invoice') {
            throw new PostingException('Only a sales invoice can be written off.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $invoice->outstanding() + 0.001) {
            throw new PostingException('Write-off must be between 0 and the outstanding (' . number_format($invoice->outstanding(), 2) . ').');
        }

        $taxRatio = (float) $invoice->total > 0 ? (float) $invoice->tax_total / (float) $invoice->total : 0;
        $tax = round($amount * $taxRatio, 2);
        $net = round($amount - $tax, 2);

        return DB::transaction(function () use ($invoice, $amount, $net, $tax, $reason) {
            $wo = FinInvoice::create([
                'company_id' => $invoice->company_id, 'party_id' => $invoice->party_id,
                'direction' => 'receivable', 'doc_type' => 'writeoff', 'applies_to_invoice_id' => $invoice->id,
                'number' => FinNumberSeries::next($invoice->company_id, 'writeoff', 'WO-'),
                'invoice_date' => now(), 'due_date' => now(), 'currency' => $invoice->currency, 'status' => 'paid',
                'subtotal' => $net, 'tax_total' => $tax, 'total' => $amount, 'amount_paid' => $amount,
                'description' => "Bad debt write-off: {$reason}", 'created_by' => Auth::id(),
            ]);

            $debits = [['account' => $this->acc('bad_debt_account'), 'amount' => $net, 'narration' => "Write-off {$invoice->number}"]];
            if ($tax > 0) {
                $debits[] = ['account' => $this->acc('vat_output'), 'amount' => $tax, 'narration' => 'VAT reclaimed'];
            }
            $credits = [['account' => $this->acc('ar_control'), 'amount' => $amount, 'narration' => "Write-off {$invoice->number}"]];

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'ar', eventType: 'ar.writeoff', debits: $debits, credits: $credits,
                eventDate: now(), companyId: $invoice->company_id, currency: $invoice->currency,
                sourceRef: "writeoff:{$wo->id}", description: "{$wo->number} · {$invoice->party->name}",
                idempotencyKey: "arap_writeoff:{$wo->id}",
            ));
            $wo->update(['journal_id' => $journal->id]);

            $paid = round((float) $invoice->amount_paid + $amount, 2);
            $invoice->update([
                'amount_paid' => $paid,
                'status' => $paid >= (float) $invoice->total - 0.001 ? 'written_off' : 'part_paid',
            ]);

            return $wo;
        });
    }

    /**
     * Debit note against a supplier bill (the AP mirror of a credit note):
     * reverses the expense + VAT input, debits the AP control, and reduces the
     * bill balance. Tax is split at the bill's ratio.
     */
    public function createDebitNote(FinInvoice $bill, float $amount, string $reason): FinInvoice
    {
        if ($bill->direction !== 'payable' || $bill->doc_type !== 'invoice') {
            throw new PostingException('Debit notes apply to supplier bills only.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $bill->outstanding() + 0.001) {
            throw new PostingException('Debit amount must be between 0 and the bill outstanding (' . number_format($bill->outstanding(), 2) . ').');
        }

        $bill->loadMissing('lines');
        $expenseAccountId = $bill->lines->first()?->account_id;
        if (! $expenseAccountId) {
            throw new PostingException('Cannot determine the expense account to reverse.');
        }

        $taxRatio = (float) $bill->total > 0 ? (float) $bill->tax_total / (float) $bill->total : 0;
        $tax = round($amount * $taxRatio, 2);
        $net = round($amount - $tax, 2);

        return DB::transaction(function () use ($bill, $amount, $net, $tax, $reason, $expenseAccountId) {
            $dn = FinInvoice::create([
                'company_id' => $bill->company_id, 'party_id' => $bill->party_id,
                'direction' => 'payable', 'doc_type' => 'debit_note', 'applies_to_invoice_id' => $bill->id,
                'number' => FinNumberSeries::next($bill->company_id, 'debit_note', 'DN-'),
                'invoice_date' => now(), 'due_date' => now(), 'currency' => $bill->currency, 'status' => 'paid',
                'subtotal' => $net, 'tax_total' => $tax, 'total' => $amount, 'amount_paid' => $amount,
                'description' => $reason, 'created_by' => Auth::id(),
            ]);

            $debits = [['account' => $this->acc('ap_control'), 'amount' => $amount, 'narration' => "Debit note {$dn->number}"]];
            $credits = [['account' => $expenseAccountId, 'amount' => $net, 'narration' => "Debit note {$dn->number}"]];
            if ($tax > 0) {
                $credits[] = ['account' => $this->acc('vat_input'), 'amount' => $tax, 'narration' => 'VAT'];
            }

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'ap', eventType: 'ap.debit_note', debits: $debits, credits: $credits,
                eventDate: now(), companyId: $bill->company_id, currency: $bill->currency,
                sourceRef: "debit_note:{$dn->id}", description: "{$dn->number} · {$bill->party->name}",
                idempotencyKey: "arap_debitnote:{$dn->id}",
            ));
            $dn->update(['journal_id' => $journal->id]);

            $paid = round((float) $bill->amount_paid + $amount, 2);
            $bill->update([
                'amount_paid' => $paid,
                'status' => $paid >= (float) $bill->total - 0.001 ? 'paid' : 'part_paid',
            ]);

            return $dn;
        });
    }

    /** Void an unpaid invoice by reversing its GL journal. */
    public function voidInvoice(FinInvoice $invoice): FinInvoice
    {
        if ($invoice->status === 'void') {
            return $invoice;
        }
        if ((float) $invoice->amount_paid > 0) {
            throw new PostingException('Cannot void an invoice that has payments allocated — reverse the receipt first.');
        }

        DB::transaction(function () use ($invoice) {
            if ($invoice->journal_id) {
                $this->ledger->reverse($invoice->journal->fresh('lines'), "Void invoice {$invoice->number}");
            }
            $invoice->update(['status' => 'void']);
        });

        return $invoice->fresh();
    }

    private function resolveBankId(string $code): int
    {
        return (int) \App\Modules\Finance\Accounting\Models\FinAccount::where('code', $code)->value('id');
    }
}
