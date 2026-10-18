<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinInvoiceLine;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A tax invoice has to identify the client well enough to be claimed against.
 *
 * The rendered invoice carried the client's name, their email and — if it happened to have been
 * captured — a VAT number. Everything else the party record already held went unprinted: the
 * postal address, the registration number, the contact person, the account code, the sales rep who
 * owns the relationship, and any discount that had been applied.
 *
 * That is not only a thin-looking page. A recipient claiming input VAT needs the invoice to name
 * them properly, and one addressed to a trust with no address and no VAT number is the one their
 * bookkeeper sends back. The data was there the whole time; the template simply never asked for it.
 *
 * Rendered as markup rather than inspected as PDF bytes: the question is what the template puts on
 * the page, and the view answers it exactly instead of guessing at compressed output.
 */
class InvoiceShowsTheClientTest extends TestCase
{
    use DatabaseTransactions;

    private function party(array $attributes = []): FinParty
    {
        return FinParty::create(array_merge([
            'kind'                => 'customer',
            'code'                => 'C' . Str::random(6),
            'name'                => 'Osizweni Education & Development Trust',
            'email'               => 'jtshabalala@osizweni.org.za',
            'phone'               => '+27 17 123 4567',
            'contact_name'        => 'J Tshabalala',
            'address'             => 'P.O. Box 6377',
            'city'                => 'Secunda',
            'country'             => 'South Africa',
            'postal_address'      => '2302',
            'vat_number'          => '4370228530',
            'registration_number' => '2004/012345/08',
            'customer_number'     => 'OSZ001',
            'sales_rep'           => 'Francois Charles Keou',
            'credit_terms_days'   => 30,
        ], $attributes));
    }

    private function invoice(FinParty $party, array $attributes = []): FinInvoice
    {
        $invoice = FinInvoice::create(array_merge([
            'uuid'         => (string) Str::uuid(),
            'party_id'     => $party->id,
            'direction'    => 'receivable',
            'number'       => 'INV-' . Str::upper(Str::random(6)),
            'party_ref'    => 'PO-4471',
            'invoice_date' => now()->toDateString(),
            'due_date'     => now()->addDays(30)->toDateString(),
            'currency'     => 'ZAR',
            'status'       => 'open',
            'subtotal'     => 9000,
            'tax_total'    => 0,
            'total'        => 9000,
            'amount_paid'  => 0,
        ], $attributes));

        FinInvoiceLine::create([
            'invoice_id'  => $invoice->id,
            // A line posts to an account; the column is NOT NULL, so the shared chart supplies one.
            'account_id'  => \App\Modules\Finance\Accounting\Models\FinAccount::query()->value('id'),
            'description' => 'SDHR — Server migration',
            'quantity'    => 4,
            'unit_price'  => 750,
            'tax_rate'    => 0,
            'line_total'  => 3000,
        ]);

        return $invoice->fresh(['lines', 'party']);
    }

    private function render(FinInvoice $invoice): string
    {
        $documents = app(FinanceDocumentService::class);

        return View::make('pdf.finance.invoice', [
            'company'  => $documents->company(),
            'invoice'  => $invoice->load('lines', 'party'),
            'qr'       => null,
            'terms'    => null,
            'template' => null,
            'style'    => ['accent' => '#4b3fd4', 'font' => 'DejaVu Sans, sans-serif', 'layout' => 'classic'],
        ])->render();
    }

    // ── Who it is for ────────────────────────────────────────────────────────

    public function test_the_clients_full_address_is_printed(): void
    {
        $html = $this->render($this->invoice($this->party()));

        foreach (['P.O. Box 6377', 'Secunda', '2302', 'South Africa', 'J Tshabalala'] as $expected) {
            $this->assertStringContainsString($expected, $html, "The invoice omitted: {$expected}");
        }
    }

    public function test_the_numbers_a_bookkeeper_reconciles_against_are_printed(): void
    {
        // A VAT number is the difference between an invoice that can be claimed and one that is
        // returned; the registration number and account code are what it gets filed under.
        $html = $this->render($this->invoice($this->party()));

        $this->assertStringContainsString('4370228530', $html);
        $this->assertStringContainsString('2004/012345/08', $html);
        $this->assertStringContainsString('OSZ001', $html);
    }

    public function test_the_reference_and_the_sales_rep_are_named(): void
    {
        /*
         * The client's own purchase-order reference is how they match the invoice to their
         * commitment, and the rep is who they ring about it. Both were held and neither was shown.
         */
        $html = $this->render($this->invoice($this->party()));

        $this->assertStringContainsString('PO-4471', $html);
        $this->assertStringContainsString('Francois Charles Keou', $html);
        $this->assertStringContainsString('30 days', $html);
    }

    public function test_a_client_with_only_a_name_still_renders(): void
    {
        /*
         * Most records are not complete. Every one of these fields is optional, and a template that
         * printed empty labels would look worse than the thin one it replaced.
         */
        $sparse = $this->party([
            'name' => 'Walk-in Customer', 'email' => null, 'phone' => null, 'contact_name' => null,
            'address' => null, 'city' => null, 'country' => null, 'postal_address' => null,
            'vat_number' => null, 'registration_number' => null, 'customer_number' => null,
            // Not null: the column refuses one. Zero is the same fact for the template's purposes —
              // no agreed terms to print.
              'sales_rep' => null, 'credit_terms_days' => 0,
        ]);

        $html = $this->render($this->invoice($sparse, ['party_ref' => null]));

        $this->assertStringContainsString('Walk-in Customer', $html);
        $this->assertStringNotContainsString('VAT No:', $html);
        $this->assertStringNotContainsString('Sales rep', $html);
    }

    // ── What it comes to ────────────────────────────────────────────────────

    public function test_a_discount_is_shown_when_there_is_one(): void
    {
        $html = $this->render($this->invoice($this->party(), ['discount_total' => 500]));

        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('500.00', $html);
    }

    public function test_no_discount_line_is_drawn_when_there_is_no_discount(): void
    {
        // A row reading 0.00 invites the question of why it is there at all.
        $html = $this->render($this->invoice($this->party(), ['discount_total' => 0]));

        $this->assertStringNotContainsString('Discount', $html);
    }

    public function test_the_amount_due_accounts_for_what_has_been_paid(): void
    {
        $html = $this->render($this->invoice($this->party(), ['amount_paid' => 2000]));

        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('7,000.00', $html);
    }

    public function test_the_pdf_renderer_still_produces_a_document(): void
    {
        // The markup assertions above would all pass on a template that dompdf then choked on.
        $pdf = app(FinanceDocumentService::class)->invoicePdf($this->invoice($this->party()));

        $this->assertStringStartsWith('%PDF', $pdf->output());
    }

    // ── How it looks ────────────────────────────────────────────────────────

    public function test_the_same_address_is_not_printed_twice(): void
    {
        /*
         * A postal address copied into the physical field — or the reverse — is the ordinary state
         * of a customer record, and printing the two as separate lines put the client's address on
         * the invoice twice. That reads as a fault in the document rather than in the record.
         */
        $party = $this->party([
            'address'        => 'P.O. Box 6377, Secunda, 2302',
            'postal_address' => 'P.O. Box 6377, Secunda, 2302',
        ]);

        $html = $this->render($this->invoice($party));

        $this->assertSame(1, substr_count($html, 'P.O. Box 6377, Secunda, 2302'));
    }

    public function test_the_vat_number_is_a_labelled_line_of_its_own(): void
    {
        /*
         * Which is what stops people typing it into the address field to make it appear at all —
         * the habit that produced the duplicated address above.
         */
        $html = $this->render($this->invoice($this->party()));

        $this->assertStringContainsString('VAT number', $html);
        $this->assertStringContainsString('4370228530', $html);
    }

    public function test_nothing_is_filled_with_a_brand_colour(): void
    {
        /*
         * A financial document is read for its numbers. The accent used to sit on the title, the
         * table header and the block carrying the total — three of the strongest marks on the page,
         * none of them the figure the reader came for. Weight and space do that work now.
         */
        $documents = app(FinanceDocumentService::class);
        $style = new \ReflectionMethod($documents, 'style');
        $style->setAccessible(true);

        $resolved = $style->invoke($documents, null);

        $this->assertSame('#374151', $resolved['accent'], 'The default document colour is ink.');
        $this->assertSame('right', $resolved['logo_position']);
        $this->assertGreaterThanOrEqual(90, $resolved['logo_height']);
    }

    public function test_a_tenant_that_wants_its_own_colour_still_gets_one(): void
    {
        // Removing the default is not removing the choice.
        $template = \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Branded', 'doc_type' => 'invoice',
            'accent_color' => '#0ea5e9', 'use_accent' => true, 'layout' => 'letterhead',
        ]);

        $documents = app(FinanceDocumentService::class);
        $style = new \ReflectionMethod($documents, 'style');
        $style->setAccessible(true);

        $this->assertSame('#0ea5e9', $style->invoke($documents, $template)['accent']);
    }

    public function test_a_template_with_a_colour_it_never_asked_for_stays_grey(): void
    {
        /*
         * Every seeded template carried an accent, which is why they all looked like the same
         * document in four colours. An untouched one now reads as "no choice made".
         */
        $template = \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Seeded', 'doc_type' => 'invoice',
            'accent_color' => '#4b3fd4', 'layout' => 'letterhead',
        ]);

        $documents = app(FinanceDocumentService::class);
        $style = new \ReflectionMethod($documents, 'style');
        $style->setAccessible(true);

        $this->assertSame('#374151', $style->invoke($documents, $template)['accent']);
    }

    public function test_the_starter_templates_are_four_arrangements_not_four_colours(): void
    {
        $set = \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::starterSet();

        $layouts = array_column($set, 'layout');
        $colours = array_unique(array_column($set, 'accent_color'));

        $this->assertSame($layouts, array_unique($layouts), 'Each starter template is a different arrangement.');
        $this->assertCount(1, $colours, 'And none of them is distinguished by colour.');
    }

    // ── A VAT number that was typed into the address ─────────────────────────

    public function test_a_vat_number_left_in_the_address_is_printed_once_and_as_a_vat_number(): void
    {
        /*
         * The state of the live book. Before the invoice had a VAT line, appending the number to
         * the address was the only way to get it onto the page, so addresses read like this — and
         * printing the address verbatim beside a VAT line would show the number twice.
         */
        $party = $this->party([
            'address'    => 'P.O. Box 6377, Secunda, 2302, VAT NO: 4370228530',
            'vat_number' => null,
            'postal_address' => null,
        ]);

        $html = $this->render($this->invoice($party));

        $this->assertStringNotContainsString('VAT NO: 4370228530', $html,
            'The address was printed with the VAT number still embedded in it.');
        $this->assertStringContainsString('P.O. Box 6377, Secunda, 2302', $html,
            'Stripping the VAT number took the rest of the address with it.');
        // Moved, not lost: the number still reaches the page, on the line meant for it.
        $this->assertStringContainsString('VAT number', $html);
        $this->assertStringContainsString('4370228530', $html);
        $this->assertSame(1, substr_count($html, '4370228530'),
            'The VAT number appeared more than once on the invoice.');
    }

    public function test_a_vat_number_set_properly_beats_one_found_in_the_address(): void
    {
        $party = $this->party([
            'address'    => 'P.O. Box 6377, VAT NO: 1111111111',
            'vat_number' => '4370228530',
        ]);

        $html = $this->render($this->invoice($party));

        $this->assertStringContainsString('4370228530', $html);
        $this->assertStringNotContainsString('1111111111', $html,
            'A stale VAT number in the address overrode the one actually set on the record.');
    }

    public function test_an_ordinary_address_with_a_long_number_is_not_mangled(): void
    {
        $party = $this->party(['address' => 'Unit 4370228530 Industrial Park', 'vat_number' => null]);

        $html = $this->render($this->invoice($party));

        $this->assertStringContainsString('Unit 4370228530 Industrial Park', $html,
            'An unlabelled number in the address was mistaken for a VAT number.');
    }

    /*
     * The vendor number is not here.
     *
     * Two tests used to sit at this point asserting that a company-wide vendor number reached the
     * document payload and printed on the letterhead. That was the old design and it was wrong: a
     * vendor number is the supplier account one particular client opened for you on their own
     * purchasing system, so a single shared one is wrong on every invoice but the first — and
     * wrong in the quiet way, because the document looks complete and simply never matches.
     *
     * It moved onto the customer, and onto the invoice and the quote at the moment of issue.
     * VendorNumberIsPerCustomerTest owns that, and asserts the letterhead no longer carries one.
     */
}
