<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerChainTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function event(float $amount, string $key): FinancialEvent
    {
        return new FinancialEvent(
            sourceModule: 'test', eventType: 'test.chain',
            debits:  [['account' => '1100', 'amount' => $amount]],
            credits: [['account' => '4100', 'amount' => $amount]],
            eventDate: now(), idempotencyKey: $key,
        );
    }

    public function test_journals_chain_and_verify(): void
    {
        $ledger = app(Ledger::class);
        $j1 = $ledger->post($this->event(100, 'chain-a-' . uniqid()));
        $j2 = $ledger->post($this->event(200, 'chain-b-' . uniqid()));

        // Each journal carries a 64-char hash; the second links to the first.
        $this->assertEquals(64, strlen($j1->hash));
        $this->assertEquals($j1->hash, $j2->fresh()->prev_hash);
        $this->assertEquals($j2->seq, $j1->seq + 1);

        // A journal's stored hash must equal a fresh recompute of its lines.
        $this->assertEquals($j1->hash, $ledger->recomputeHash($j1->fresh()->load('lines')));
    }

    public function test_tampering_a_line_breaks_the_hash(): void
    {
        $ledger = app(Ledger::class);
        $j = $ledger->post($this->event(100, 'chain-tamper-' . uniqid()));
        $original = $j->hash;

        // Tamper directly in the DB (bypassing the immutability guard).
        DB::table('fin_journal_lines')->where('journal_id', $j->id)
            ->where('debit', '>', 0)->update(['debit' => 999]);

        $recomputed = $ledger->recomputeHash($j->fresh()->load('lines'));
        $this->assertNotEquals($original, $recomputed, 'A tampered line must produce a different hash.');
    }
}
