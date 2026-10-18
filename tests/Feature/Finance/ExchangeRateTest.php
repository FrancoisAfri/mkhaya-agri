<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinExchangeRate;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.base_currency' => 'ZAR']);
        if (! FinAccount::where('code', '1100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    private function usdEvent(float $amount, string $key): FinancialEvent
    {
        return new FinancialEvent(
            sourceModule: 'test', eventType: 'test.fx',
            debits:  [['account' => '1100', 'amount' => $amount]],
            credits: [['account' => '4100', 'amount' => $amount]],
            eventDate: now(), currency: 'USD', idempotencyKey: $key,
        );
    }

    public function test_direct_and_inverse_lookup(): void
    {
        FinExchangeRate::create([
            'base_currency' => 'USD', 'quote_currency' => 'ZAR', 'rate' => 18.5,
            'rate_type' => 'spot', 'effective_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertEquals(18.5, FinExchangeRate::rateFor('USD', 'ZAR', now()));
        $this->assertEqualsWithDelta(1 / 18.5, FinExchangeRate::rateFor('ZAR', 'USD', now()), 0.0001);
        $this->assertEquals(1.0, FinExchangeRate::rateFor('ZAR', 'ZAR', now()));
    }

    public function test_foreign_currency_posting_converts_to_base(): void
    {
        FinExchangeRate::create([
            'base_currency' => 'USD', 'quote_currency' => 'ZAR', 'rate' => 18.5,
            'rate_type' => 'spot', 'effective_date' => now()->subDay()->toDateString(),
        ]);

        $journal = app(Ledger::class)->post($this->usdEvent(100, 'fx-' . uniqid()));
        $debitLine = $journal->lines->firstWhere('debit', '>', 0);

        // Transaction currency preserved; base columns converted at the rate.
        $this->assertEquals('USD', $debitLine->currency);
        $this->assertEquals(100.0, (float) $debitLine->debit);
        $this->assertEquals(1850.0, (float) $debitLine->base_debit);
    }

    public function test_missing_rate_is_rejected(): void
    {
        $this->expectException(PostingException::class);
        app(Ledger::class)->post($this->usdEvent(50, 'fx-none-' . uniqid()));
    }
}
