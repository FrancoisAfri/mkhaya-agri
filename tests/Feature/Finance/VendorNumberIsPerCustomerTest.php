<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A vendor number belongs to a customer, not to the company.
 *
 * It was added to the accounting settings page and printed on the letterhead of every document the
 * tenant issues — which is what a registration number is, and a vendor number is not that. It is
 * the supplier account the *client* opened for you on *their* purchasing system: one per client,
 * and the field their accounts-payable clerk matches on before anything gets paid. A single shared
 * number is wrong on every invoice but the first, and wrong in the quiet way — the document looks
 * complete and simply never matches.
 *
 * So it lives on the customer, is carried onto the invoice and the quote at the moment of issue,
 * and is gone from the settings page and the letterhead.
 */
class VendorNumberIsPerCustomerTest extends TestCase
{
    use DatabaseTransactions;

    /*
     * The chart of accounts and an open period, because an invoice posts to the GL on creation.
     * Seeded rather than skipped around: a test that skips when the ledger is empty reads as a pass
     * and proves nothing about the thing it was written for.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }

        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    private function customer(?string $vendorNumber): FinParty
    {
        return FinParty::create([
            'kind'              => 'customer',
            'code'              => 'C' . Str::random(6),
            'name'              => 'Client ' . Str::random(5),
            'credit_terms_days' => 30,
            'vendor_number'     => $vendorNumber,
        ]);
    }

    /** Any postable income account; the posting is not what these tests are about. */
    private function incomeAccount(): int
    {
        return (int) FinAccount::where('code', '4100')->value('id');
    }

    private function actingAsFinanceUser(): self
    {
        $user = \App\Core\User\Models\User::first() ?? \App\Core\User\Models\User::factory()->create();

        return $this->actingAs($user)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ]);
    }

    // ── It is captured on the customer ───────────────────────────────────────

    public function test_a_vendor_number_can_be_saved_against_a_customer(): void
    {
        $party = $this->customer(null);

        $this->actingAsFinanceUser()
            ->patch(route('finance.accounting.parties.update', $party->id), [
                'kind' => 'customer', 'name' => $party->name, 'credit_terms_days' => 30,
                'vendor_number' => 'SUP-88213',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('SUP-88213', $party->fresh()->vendor_number);
    }

    /** Two customers, two numbers — the whole reason it could not stay in settings. */
    public function test_two_customers_hold_different_numbers(): void
    {
        $eskom = $this->customer('ESK-0041');
        $transnet = $this->customer('TFR-99120');

        $this->assertSame('ESK-0041', $eskom->fresh()->vendor_number);
        $this->assertSame('TFR-99120', $transnet->fresh()->vendor_number);
    }

    // ── It reaches the document ──────────────────────────────────────────────

    public function test_an_invoice_takes_the_number_from_the_customer_it_is_for(): void
    {
        $party = $this->customer('ESK-0041');

        $invoice = app(\App\Modules\Finance\Accounting\Services\ArApService::class)->createInvoice([
            'party_id'  => $party->id,
            'direction' => 'receivable',
            'lines'     => [['account_id' => $this->incomeAccount(), 'description' => 'Design fee', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);

        $this->assertSame('ESK-0041', $invoice->vendor_number,
            'The invoice did not pick up the vendor number the customer holds.');
    }

    /**
     * Stamped onto the document, not read back through the party.
     *
     * A client who changes their purchasing system issues new supplier numbers. The invoices
     * already sitting in their accounts payable are matched under the old one, and rewriting them
     * from the party record would silently break every one of them.
     */
    public function test_changing_the_customer_does_not_rewrite_invoices_already_issued(): void
    {
        $party = $this->customer('OLD-1000');

        $invoice = app(\App\Modules\Finance\Accounting\Services\ArApService::class)->createInvoice([
            'party_id'  => $party->id,
            'direction' => 'receivable',
            'lines'     => [['account_id' => $this->incomeAccount(), 'description' => 'Design fee', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);

        $party->update(['vendor_number' => 'NEW-2000']);

        $this->assertSame('OLD-1000', FinInvoice::find($invoice->id)->vendor_number);
    }

    /** Typed on the invoice itself, it wins — a correction must not be overwritten by a default. */
    public function test_a_number_given_on_the_invoice_beats_the_customer_default(): void
    {
        $party = $this->customer('ESK-0041');

        $invoice = app(\App\Modules\Finance\Accounting\Services\ArApService::class)->createInvoice([
            'party_id'      => $party->id,
            'direction'     => 'receivable',
            'vendor_number' => 'ESK-0041-B',
            'lines'         => [['account_id' => $this->incomeAccount(), 'description' => 'Design fee', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);

        $this->assertSame('ESK-0041-B', $invoice->vendor_number);
    }

    public function test_a_quote_takes_the_number_from_the_customer_too(): void
    {
        $party = $this->customer('TFR-99120');

        $quote = app(\App\Modules\Finance\Accounting\Services\QuoteService::class)->createQuote([
            'party_id' => $party->id,
            'lines'    => [['account_id' => $this->incomeAccount(), 'description' => 'Scope of works', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 15]],
        ]);

        $this->assertSame('TFR-99120', $quote->vendor_number);
    }

    // ── And it is gone from the company's own details ────────────────────────

    /** The letterhead prints what identifies us to everyone. A vendor number is not that. */
    public function test_the_company_letterhead_no_longer_carries_one(): void
    {
        $settings = FinSetting::current();
        $settings->vendor_number = 'LEFTOVER-1';
        $settings->save();

        $company = app(FinanceDocumentService::class)->company();

        $this->assertArrayNotHasKey('vendor', $company,
            'A single company-wide vendor number is still being put on every document.');
    }

    /** Saving the settings page must not be able to set one either. */
    public function test_the_settings_page_neither_offers_nor_accepts_one(): void
    {
        $props = $this->actingAsFinanceUser()
            ->get(route('finance.accounting.settings'))
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('vendor_number', $props['settings']);
    }
}
