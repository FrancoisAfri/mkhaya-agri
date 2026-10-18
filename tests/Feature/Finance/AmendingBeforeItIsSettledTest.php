<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Finance\Accounting\Services\QuoteService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Changing a quote or an invoice before it has been settled.
 *
 * A customer reads a quote and asks for a line to come off; a typo is spotted on an invoice before
 * anybody has paid it. Neither was possible: a quote froze the moment it was sent, and an invoice
 * had no edit path at all — only a credit note, which puts a correction of a typo into the
 * permanent record as though something had been sold and returned.
 *
 * The constraint that shapes all of this is that an invoice is not a draft. It posts to the ledger
 * when it is raised, so amending one means reversing that entry and posting a fresh one, and there
 * are states where that cannot honestly be done.
 */
class AmendingBeforeItIsSettledTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder)->run();
        }

        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    private function party(): FinParty
    {
        return FinParty::create([
            'kind' => 'customer', 'code' => 'P'.uniqid(), 'name' => 'Acme', 'credit_terms_days' => 30,
        ]);
    }

    private function income(): int
    {
        return (int) FinAccount::where('code', '4100')->value('id');
    }

    private function glBalance(string $code): float
    {
        $row = app(FinanceReportService::class)
            ->accountBalances(null, now()->toDateString())->firstWhere('code', $code);

        return round((float) ($row['balance'] ?? 0), 2);
    }

    private function invoice(float $unitPrice = 1000): FinInvoice
    {
        return app(ArApService::class)->createInvoice([
            'party_id' => $this->party()->id, 'direction' => 'receivable',
            'lines' => [[
                'account_id' => $this->income(), 'description' => 'Widgets',
                'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => 15,
            ]],
        ]);
    }

    private function amend(FinInvoice $invoice, float $unitPrice, string $description = 'Widgets'): FinInvoice
    {
        return app(ArApService::class)->updateInvoice($invoice, [
            'lines' => [[
                'account_id' => $this->income(), 'description' => $description,
                'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => 15,
            ]],
        ]);
    }

    // ── The invoice, and the ledger behind it ───────────────────────────────────────────────────

    public function test_an_unpaid_invoice_can_be_corrected(): void
    {
        $invoice = $this->invoice(1000);

        $amended = $this->amend($invoice, 1200, 'Widgets, corrected');

        $this->assertEquals(1200.0, (float) $amended->subtotal);
        $this->assertEquals(180.0, (float) $amended->tax_total);
        $this->assertEquals(1380.0, (float) $amended->total);
        $this->assertSame('Widgets, corrected', $amended->lines->first()->description);
    }

    public function test_the_ledger_ends_up_saying_what_the_invoice_says(): void
    {
        $before = $this->glBalance('1200');

        $invoice = $this->invoice(1000);
        $this->assertEquals($before + 1150, $this->glBalance('1200'));

        $this->amend($invoice, 1200);

        /*
         * The whole point. Leaving the original entry standing would put the control account
         * permanently out of step with the document that justifies it, and the difference would
         * surface months later as a balance nobody can explain.
         */
        $this->assertEquals($before + 1380, $this->glBalance('1200'),
            'The receivables control still carries the amount the invoice used to say.');
    }

    public function test_the_old_entry_is_reversed_rather_than_deleted(): void
    {
        $invoice = $this->invoice(1000);
        $original = $invoice->journal_id;

        $amended = $this->amend($invoice, 1200);

        $this->assertNotSame($original, $amended->journal_id, 'The invoice still points at its old entry.');

        // An auditor has to be able to see what it said before, what it says now, and that the two
        // reconcile — which a deleted journal does not allow.
        $this->assertTrue(
            DB::table('fin_journals')->where('id', $original)->exists(),
            'The original journal was removed instead of reversed.'
        );
    }

    public function test_the_invoice_keeps_its_number(): void
    {
        $invoice = $this->invoice(1000);
        $number = $invoice->number;

        // An amended invoice is the same document, and a gap in an invoice sequence is a question
        // from SARS rather than a tidy-up.
        $this->assertSame($number, $this->amend($invoice, 1200)->number);
    }

    // ── Where it must refuse ────────────────────────────────────────────────────────────────────

    public function test_an_invoice_with_money_against_it_is_refused(): void
    {
        $invoice = $this->invoice(1000);

        DB::table('fin_invoices')->where('id', $invoice->id)->update(['amount_paid' => 500]);

        // Reversing an entry a receipt is allocated to would strand the payment against an amount
        // that no longer exists.
        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/money allocated|reverse the receipt/i');

        $this->amend($invoice->fresh(), 1200);
    }

    public function test_a_voided_invoice_is_refused(): void
    {
        $invoice = $this->invoice(1000);
        DB::table('fin_invoices')->where('id', $invoice->id)->update(['status' => 'void']);

        $this->expectExceptionMessageMatches('/voided invoice/i');

        $this->amend($invoice->fresh(), 1200);
    }

    public function test_an_invoice_that_has_taken_stock_out_is_refused(): void
    {
        $invoice = $this->invoice(1000);

        // Relief is one-way here — there is no inverse that rebuilds the cost layers it consumed.
        DB::table('fin_stock_movements')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $invoice->company_id,
            'item_id' => app(\App\Modules\Finance\Accounting\Services\InventoryService::class)
                ->createItem(['code' => 'SKU'.uniqid(), 'name' => 'Widget', 'cost_method' => 'fifo'])->id,
            'type' => 'ISSUE', 'qty' => 1, 'unit_cost' => 10, 'value' => 10,
            'movement_date' => now(), 'reference' => "invoice:{$invoice->id}",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/stock out of the storeroom|credit note/i');

        $this->amend($invoice->fresh(), 1200);
    }

    // ── The quote ───────────────────────────────────────────────────────────────────────────────

    public function test_a_sent_quote_can_still_be_changed(): void
    {
        $quote = $this->quote();
        app(QuoteService::class)->changeStatus($quote, 'sent');

        $changed = app(QuoteService::class)->updateQuote($quote->fresh(), [
            'lines' => [['account_id' => $this->income(), 'description' => 'One line only',
                'quantity' => 1, 'unit_price' => 900, 'tax_rate' => 15]],
        ]);

        $this->assertEquals(900.0, (float) $changed->subtotal);
    }

    public function test_changing_an_accepted_quote_takes_the_acceptance_back(): void
    {
        $quote = $this->quote();
        app(QuoteService::class)->changeStatus($quote, 'sent');
        app(QuoteService::class)->changeStatus($quote->fresh(), 'accepted');

        $changed = app(QuoteService::class)->updateQuote($quote->fresh(), [
            'lines' => [['account_id' => $this->income(), 'description' => 'Different figures',
                'quantity' => 1, 'unit_price' => 2000, 'tax_rate' => 15]],
        ]);

        /*
         * The customer agreed to the figures they were shown. Leaving the acceptance attached to
         * different ones would record an agreement that was never given — and it is the version
         * somebody would point at later.
         */
        $this->assertSame('draft', $changed->status,
            'An accepted quote was changed and stayed accepted, against figures nobody agreed to.');
    }

    public function test_an_invoiced_quote_is_frozen(): void
    {
        $quote = $this->quote();
        DB::table('fin_quotes')->where('id', $quote->id)->update(['status' => 'invoiced']);

        $this->expectExceptionMessageMatches('/can no longer be edited/i');

        app(QuoteService::class)->updateQuote($quote->fresh(), [
            'lines' => [['account_id' => $this->income(), 'description' => 'Too late',
                'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 15]],
        ]);
    }

    private function quote(): FinQuote
    {
        return app(QuoteService::class)->createQuote([
            'party_id' => $this->party()->id,
            'lines' => [['account_id' => $this->income(), 'description' => 'Widgets',
                'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 15]],
        ]);
    }
}
