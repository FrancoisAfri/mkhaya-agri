<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Mail\FinanceDocumentMail;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class FinanceDocumentController extends Controller
{
    public function __construct(private FinanceDocumentService $docs)
    {
    }

    // ── Quote ───────────────────────────────────────────────────────────────

    public function quotePdf(FinQuote $quote)
    {
        return $this->docs->quotePdf($quote)->download("Quote-{$quote->number}.pdf");
    }

    public function emailQuote(FinQuote $quote): RedirectResponse
    {
        $this->email(
            $quote->party, "Quote {$quote->number}", $quote->party->name,
            "<p>Please find attached quote <strong>{$quote->number}</strong>, valid until "
                . optional($quote->valid_until)->format('d M Y') . '.</p>',
            $this->docs->quotePdf($quote)->output(), "Quote-{$quote->number}.pdf"
        );

        // Sent → mark the quote as sent (unless already further along).
        if ($quote->status === 'draft') {
            $quote->update(['status' => 'sent']);
        }

        return back()->with('success', "Quote emailed to {$quote->party->email}.");
    }

    // ── Invoice / bill ──────────────────────────────────────────────────────

    public function invoicePdf(FinInvoice $invoice)
    {
        return $this->docs->invoicePdf($invoice)->download("{$invoice->number}.pdf");
    }

    public function emailInvoice(FinInvoice $invoice): RedirectResponse
    {
        $noun = $invoice->direction === 'receivable' ? 'invoice' : 'bill';
        $this->email(
            $invoice->party, "Invoice {$invoice->number}", $invoice->party->name,
            "<p>Please find attached {$noun} <strong>{$invoice->number}</strong> due "
                . optional($invoice->due_date)->format('d M Y') . '.</p>',
            $this->docs->invoicePdf($invoice)->output(), "{$invoice->number}.pdf"
        );

        return back()->with('success', "Invoice emailed to {$invoice->party->email}.");
    }

    // ── WhatsApp ────────────────────────────────────────────────────────────

    public function whatsappInvoice(FinInvoice $invoice): RedirectResponse
    {
        $due = optional($invoice->due_date)->format('d M Y');
        $body = "Hi {$invoice->party->name}, invoice {$invoice->number} for {$invoice->currency} "
            . number_format((float) $invoice->total, 2) . " is due {$due}. Amount due: "
            . number_format($invoice->outstanding(), 2) . ". Reference: {$invoice->number}.";

        return $this->whatsapp($invoice->party, $body);
    }

    public function whatsappQuote(FinQuote $quote): RedirectResponse
    {
        $valid = optional($quote->valid_until)->format('d M Y');
        $body = "Hi {$quote->party->name}, your quote {$quote->number} for {$quote->currency} "
            . number_format((float) $quote->total, 2) . " is valid until {$valid}. Reply to accept.";

        return $this->whatsapp($quote->party, $body);
    }

    private function whatsapp(FinParty $party, string $body): RedirectResponse
    {
        $wa = app(\App\Modules\CRM\Services\CrmWhatsAppService::class);
        if (! $party->phone) {
            return back()->with('error', 'This party has no phone number on file.');
        }
        if (! $wa->isConfigured()) {
            return back()->with('error', 'WhatsApp is not configured.');
        }

        try {
            $wa->sendText($party->phone, $body);
        } catch (\Throwable $e) {
            Log::error('Finance WhatsApp send failed', ['party' => $party->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not send the WhatsApp message.');
        }

        return back()->with('success', "WhatsApp sent to {$party->phone}.");
    }

    // ── Statement ───────────────────────────────────────────────────────────

    public function deliveryNotePdf(\App\Modules\Finance\Accounting\Models\FinDeliveryNote $deliveryNote)
    {
        return $this->docs->deliveryNotePdf($deliveryNote)->download("{$deliveryNote->number}.pdf");
    }

    public function statementPdf(Request $request, FinParty $party)
    {
        [$from, $to] = [$request->date('from')?->toDateString(), $request->date('to')?->toDateString()];

        return $this->docs->statementPdf($party, $from, $to)->download("Statement-{$party->code}.pdf");
    }

    public function emailStatement(Request $request, FinParty $party): RedirectResponse
    {
        [$from, $to] = [$request->date('from')?->toDateString(), $request->date('to')?->toDateString()];
        $this->email(
            $party, "Statement of account — {$party->name}", $party->name,
            '<p>Please find attached your statement of account.</p>',
            $this->docs->statementPdf($party, $from, $to)->output(), "Statement-{$party->code}.pdf"
        );

        return back()->with('success', "Statement emailed to {$party->email}.");
    }

    private function email(FinParty $party, string $subject, string $greeting, string $body, string $pdf, string $filename): void
    {
        if (! $party->email) {
            throw ValidationException::withMessages(['email' => ['This party has no email address on file.']]);
        }

        try {
            Mail::to($party->email)->send(new FinanceDocumentMail($subject, $greeting, $body, $pdf, $filename));
        } catch (\Throwable $e) {
            Log::error('Finance document email failed', ['party' => $party->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['email' => ['Could not send the email — check the mail settings.']]);
        }
    }
}
