<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Renders Finance documents (quote, invoice, statement) to PDF with the company
 * letterhead. Used for download and as email attachments.
 */
class FinanceDocumentService
{
    /** The default document colour: near-black, for print. */
    private const INK = '#374151';

    public function __construct(
        private AgeingService $ageing,
        private QrCodeService $qr,
    ) {
    }

    /**
     * Letterhead + banking + message for finance documents. Finance settings
     * (fin_settings) win; anything left blank falls back to the system Company
     * Identity so it works out of the box.
     */
    public function company($tpl = null): array
    {
        $c = DB::table('company_identities')->first();
        $s = \App\Modules\Finance\Accounting\Models\FinSetting::current();

        $showLogo = $tpl ? (bool) $tpl->show_logo : true;
        $showBank = $tpl ? (bool) $tpl->show_bank_details : true;

        return [
            'name'    => $s->company_name ?: ($c->full_company_name ?? $c->company_name ?? config('app.name')),
            'address' => $s->address ?: ($c->mailing_address ?? null),
            'email'   => $s->email ?: ($c->support_email ?? null),
            'phone'   => $s->phone ?: null,
            'website' => $s->website ?: ($c->company_website ?? null),
            'vat'     => $s->vat_number ?: null,
            'reg'     => $s->registration_number ?: null,
            /*
             * No vendor number here, deliberately.
             *
             * It is the supplier account a *client* opened for you on *their* purchasing system —
             * one per client, and the field their accounts-payable clerk matches on. A single
             * company-wide number is wrong on every invoice but the first, and wrong in the quiet
             * way: the document looks complete and simply never matches. It lives on the customer
             * and is carried onto the invoice and quote at the moment of issue. See
             * VendorNumberIsPerCustomerTest.
             */
            'logo'    => $showLogo ? $this->logo($s->logo_path ?: ($s->logo ?: ($c->company_logo ?? null))) : null,
            // Template accent colour wins over the system theme colour.
            'colour'  => ($tpl && $tpl->accent_color) ? $tpl->accent_color : ($c->sys_theme_color ?? '#4b3fd4'),
            'bank'    => $showBank ? array_filter([
                'Bank'    => $s->bank_name,
                'Account name' => $s->bank_account_name,
                'Account no.'  => $s->bank_account_number,
                'Branch'  => $s->branch_code,
                'SWIFT'   => $s->swift,
            ]) : [],
            'message' => $s->default_message ?: null,
            'footer_note' => $tpl?->footer_note,
        ];
    }

    /**
     * The letterhead logo as bytes the renderer can draw.
     *
     * Always inlined, never left as a link. The PDF is built server-side and a URL would send the
     * renderer back through this application's own auth to fetch it, which fails quietly and
     * prints an invoice with no branding on it.
     *
     * Three places to look, because a logo can be in any of them: the shared disk, which is where
     * uploads go now; the old column holding a data URI, for a business whose settings predate
     * that; and a path under public/, for the system logo the installer drops there.
     */
    private function logo(?string $logo): ?string
    {
        if (! $logo) {
            return null;
        }

        if (str_starts_with($logo, 'data:')) {
            return $logo;
        }

        if (Storage::disk('documents')->exists($logo)) {
            $bytes = Storage::disk('documents')->get($logo);

            if (! blank($bytes)) {
                return 'data:image/' . (pathinfo($logo, PATHINFO_EXTENSION) ?: 'png')
                    . ';base64,' . base64_encode($bytes);
            }
        }

        if (str_starts_with($logo, 'http')) {
            return $logo;
        }

        $path = public_path(ltrim($logo, '/'));

        return is_file($path) ? 'data:image/' . pathinfo($path, PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($path)) : null;
    }

    public function quotePdf(FinQuote $quote)
    {
        $tpl = $this->template($quote, 'quote');

        return Pdf::loadView('pdf.finance.quote', [
            'company'  => $this->company($tpl),
            'quote'    => $quote->load('lines.item', 'party'),
            'terms'    => $this->terms($quote, $tpl),
            'template' => $tpl,
            'style'    => $this->style($tpl),
        ])->setPaper($this->paperSize($tpl), $this->orientation($tpl));
    }

    public function invoicePdf(FinInvoice $invoice)
    {
        $tpl = $this->template($invoice, 'invoice');
        $company = $this->company($tpl);

        return Pdf::loadView('pdf.finance.invoice', [
            'company'  => $company,
            'invoice'  => $invoice->load('lines.account', 'party'),
            'qr'       => $this->qr->paymentQr($invoice, $company),
            'terms'    => $this->terms($invoice, $tpl),
            'template' => $tpl,
            'style'    => $this->style($tpl),
        ])->setPaper($this->paperSize($tpl), $this->orientation($tpl));
    }

    private function paperSize($tpl): string
    {
        return ($tpl && $tpl->page_size === 'letter') ? 'letter' : 'a4';
    }

    private function orientation($tpl): string
    {
        return ($tpl && $tpl->orientation === 'landscape') ? 'landscape' : 'portrait';
    }

    /**
     * Resolved styling the blade uses.
     *
     * The default is grey, not a brand colour. A financial document is read for its numbers, and a
     * saturated accent behind the total competes with the one thing on the page that matters —
     * every invoice worth copying is set in ink and paper, with the reader's eye drawn by weight
     * and spacing rather than by colour. A tenant who wants their own accent still gets it; they
     * simply no longer get it by default.
     *
     * @return array<string, mixed>
     */
    private function style($tpl): array
    {
        $layout = $tpl->layout ?? 'classic';

        // Chosen deliberately or not at all. `accent_color` carries the seeded starter colours, so
        // an untouched template reads as "no choice made" and gets the neutral.
        $accent = ($tpl && $tpl->accent_color && $tpl->use_accent) ? $tpl->accent_color : self::INK;

        return [
            'accent'        => $accent,
            'font'          => $tpl ? $tpl->fontFamily() : 'DejaVu Sans, sans-serif',
            'layout'        => $layout,
            // The mark belongs where the eye lands last on a right-aligned meta block, and every
            // invoice the client asked this to look like puts it there.
            'logo_position' => $tpl->logo_position ?? 'right',
            'logo_height'   => (int) ($tpl->logo_height ?? 92),
        ];
    }

    /** The template to render a document with: its own, else the tenant default. */
    private function template($doc, string $type): ?\App\Modules\Finance\Accounting\Models\FinDocumentTemplate
    {
        if ($doc->template_id) {
            $t = \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::find($doc->template_id);
            if ($t) {
                return $t;
            }
        }

        return \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::defaultFor($type);
    }

    /** Terms printed on the document: the doc's own snapshot, else the template's, else the setting. */
    private function terms($doc, $tpl): ?string
    {
        $t = trim((string) ($doc->terms ?? ''));
        if ($t !== '') {
            return $t;
        }
        if ($tpl && trim((string) $tpl->terms) !== '') {
            return $tpl->terms;
        }

        return \App\Modules\Finance\Accounting\Models\FinSetting::current()->default_terms ?: null;
    }

    public function deliveryNotePdf(\App\Modules\Finance\Accounting\Models\FinDeliveryNote $dn)
    {
        return Pdf::loadView('pdf.finance.delivery-note', [
            'company' => $this->company(),
            'dn'      => $dn->load('lines', 'order', 'party'),
        ])->setPaper('a4');
    }

    public function statementPdf(FinParty $party, ?string $from = null, ?string $to = null)
    {
        // Statements are branded from a template like every other customer-facing document —
        // a tenant who picks a layout expects their statements to match their invoices.
        $tpl = \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::defaultFor('statement');

        return Pdf::loadView('pdf.finance.statement', [
            'company'   => $this->company($tpl),
            'statement' => $this->ageing->statement($party->id, $from, $to),
            'template'  => $tpl,
            'style'     => $this->style($tpl),
            'terms'     => $tpl?->terms,
        ])->setPaper($this->paperSize($tpl), $this->orientation($tpl));
    }

    /**
     * Render a sample document with a template so a user can see the layout with their own
     * letterhead, colour and logo placement before committing to it.
     *
     * The sample is built in memory and never saved — previewing a template must not create a
     * document, touch numbering, or post anything to the ledger.
     */
    public function previewPdf(\App\Modules\Finance\Accounting\Models\FinDocumentTemplate $tpl, string $docType = 'invoice')
    {
        $company = $this->company($tpl);
        $style = $this->style($tpl);

        if ($docType === 'statement') {
            return Pdf::loadView('pdf.finance.statement', [
                'company'   => $company,
                'statement' => $this->sampleStatement(),
                'template'  => $tpl,
                'style'     => $style,
                'terms'     => $tpl->terms,
            ])->setPaper($this->paperSize($tpl), $this->orientation($tpl));
        }

        return Pdf::loadView('pdf.finance.invoice', [
            'company'  => $company,
            'invoice'  => $this->sampleInvoice(),
            'qr'       => null,
            'terms'    => $tpl->terms ?: 'Payment is due within 30 days of the invoice date.',
            'template' => $tpl,
            'style'    => $style,
        ])->setPaper($this->paperSize($tpl), $this->orientation($tpl));
    }

    /** An unsaved invoice with believable numbers, used only for template previews. */
    private function sampleInvoice(): FinInvoice
    {
        // There is no currency setting; take the lead from what this tenant already invoices in.
        $currency = FinInvoice::query()->value('currency') ?: 'ZAR';

        $lines = collect([
            ['description' => 'Consulting — project scoping', 'note' => '12 hours @ 850.00', 'quantity' => 12, 'unit_price' => 850.00, 'tax_rate' => 15, 'line_total' => 10200.00],
            ['description' => 'Implementation services', 'note' => null, 'quantity' => 1, 'unit_price' => 24500.00, 'tax_rate' => 15, 'line_total' => 24500.00],
            ['description' => 'Support retainer — monthly', 'note' => null, 'quantity' => 3, 'unit_price' => 1950.00, 'tax_rate' => 15, 'line_total' => 5850.00],
        ])->map(fn (array $l) => new \App\Modules\Finance\Accounting\Models\FinInvoiceLine($l));

        $subtotal = 40550.00;

        $invoice = new FinInvoice([
            'number'       => 'INV-0001 (sample)',
            'doc_type'     => 'invoice',
            'direction'    => 'receivable',
            'status'       => 'sent',
            'invoice_date' => now(),
            'due_date'     => now()->addDays(30),
            'description'  => 'This is a preview. No document has been created.',
            'currency'     => $currency,
            'subtotal'     => $subtotal,
            'tax_total'    => round($subtotal * 0.15, 2),
            'total'        => round($subtotal * 1.15, 2),
            'amount_paid'  => 0,
        ]);

        // Relations are set by hand: the model is never persisted, so load() has nothing to load.
        $invoice->setRelation('lines', $lines);
        $invoice->setRelation('party', new FinParty([
            'name'       => 'Sample Customer (Pty) Ltd',
            'email'      => 'accounts@sample-customer.co.za',
            'vat_number' => '4123456789',
        ]));

        return $invoice;
    }

    /** @return array<string,mixed> the shape AgeingService::statement() returns */
    private function sampleStatement(): array
    {
        return [
            'party'   => ['name' => 'Sample Customer (Pty) Ltd', 'code' => 'CUST-001'],
            'from'    => now()->startOfMonth()->toDateString(),
            'to'      => now()->toDateString(),
            'opening' => 12500.00,
            'rows'    => [
                ['date' => now()->subDays(24)->toDateString(), 'ref' => 'INV-0102', 'type' => 'Invoice', 'charge' => 18400.00, 'credit' => null, 'balance' => 30900.00],
                ['date' => now()->subDays(17)->toDateString(), 'ref' => 'RCT-0044', 'type' => 'Receipt', 'charge' => null, 'credit' => 12500.00, 'balance' => 18400.00],
                ['date' => now()->subDays(6)->toDateString(), 'ref' => 'INV-0119', 'type' => 'Invoice', 'charge' => 7350.00, 'credit' => null, 'balance' => 25750.00],
            ],
            'closing' => 25750.00,
        ];
    }
}
