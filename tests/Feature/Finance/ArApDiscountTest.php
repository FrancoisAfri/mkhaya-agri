<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A discount on an invoice, and the two things that have to stay true when there is one: VAT is
 * charged on what the customer actually pays, and the ledger still balances.
 */
class ArApDiscountTest extends TestCase
{
    use DatabaseTransactions;

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

    private function party(): FinParty
    {
        return FinParty::create(['kind' => 'customer', 'code' => 'P' . uniqid(), 'name' => 'Acme', 'credit_terms_days' => 30]);
    }

    private function glBalance(string $code): float
    {
        $row = app(FinanceReportService::class)->accountBalances(null, now()->toDateString())->firstWhere('code', $code);

        return round((float) ($row['balance'] ?? 0), 2);
    }

    private function invoice(array $line): \App\Modules\Finance\Accounting\Models\FinInvoice
    {
        return app(ArApService::class)->createInvoice([
            'party_id' => $this->party()->id, 'direction' => 'receivable',
            'lines' => [array_merge([
                'account_id' => FinAccount::where('code', '4100')->value('id'),
                'description' => 'Software development', 'quantity' => 1, 'unit_price' => 4000, 'tax_rate' => 15,
            ], $line)],
        ]);
    }

    public function test_a_discount_comes_off_before_vat(): void
    {
        $inv = $this->invoice(['discount_rate' => 10]);

        $this->assertEquals(400.0, (float) $inv->discount_total);
        $this->assertEquals(3600.0, (float) $inv->subtotal);
        // 15% of 3 600, not of 4 000 — VAT is due on the consideration actually charged.
        $this->assertEquals(540.0, (float) $inv->tax_total);
        $this->assertEquals(4140.0, (float) $inv->total);
    }

    public function test_the_line_keeps_the_rate_and_the_money_it_took_off(): void
    {
        $line = $this->invoice(['discount_rate' => 12.5])->lines->first();

        $this->assertEquals(12.5, (float) $line->discount_rate);
        // Stored, not re-derived: a credit note raised two years later has to reproduce the amount
        // taken off at the time, whatever the rate has been edited to since.
        $this->assertEquals(500.0, (float) $line->discount_amount);
        $this->assertEquals(3500.0, (float) $line->line_total);
    }

    public function test_the_ledger_is_posted_net_of_the_discount(): void
    {
        $arBefore = $this->glBalance('1200');
        $incomeBefore = $this->glBalance('4100');
        $vatBefore = $this->glBalance('2200');

        $this->invoice(['discount_rate' => 25]);   // 4 000 → 3 000 + 450 VAT

        // Income is stated at what was charged rather than gross with a contra beside it, so no
        // new ledger account is needed and the journal still balances.
        $this->assertEquals(round($arBefore + 3450, 2), $this->glBalance('1200'));
        $this->assertEquals(round($incomeBefore + 3000, 2), $this->glBalance('4100'));
        $this->assertEquals(round($vatBefore + 450, 2), $this->glBalance('2200'));
    }

    public function test_no_discount_behaves_exactly_as_before(): void
    {
        $inv = $this->invoice([]);

        $this->assertEquals(0.0, (float) $inv->discount_total);
        $this->assertEquals(4000.0, (float) $inv->subtotal);
        $this->assertEquals(600.0, (float) $inv->tax_total);
        $this->assertEquals(4600.0, (float) $inv->total);
    }

    /**
     * A rate above 100 is clamped rather than trusted, so the worst it can do is take the invoice
     * to zero — where the existing guard refuses it. Without the clamp it would post a negative
     * invoice and credit accounts receivable, which is a refund nobody asked for.
     */
    public function test_a_nonsense_discount_cannot_invert_the_invoice(): void
    {
        $this->expectException(\App\Modules\Finance\Accounting\Exceptions\PostingException::class);

        $this->invoice(['discount_rate' => 150]);
    }
}
