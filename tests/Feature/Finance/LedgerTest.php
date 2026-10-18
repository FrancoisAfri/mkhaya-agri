<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        $today = now();
        if (! FinFiscalPeriod::forDate($today, null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) $today->year, 'period_no' => (int) $today->month,
                'start_date' => $today->copy()->startOfMonth(), 'end_date' => $today->copy()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    private function balancedEvent(string $key = 'test-1'): FinancialEvent
    {
        return new FinancialEvent(
            sourceModule: 'test', eventType: 'ar.invoice.raised',
            debits:  [['account' => '1100', 'amount' => 1150]],
            credits: [['account' => '4100', 'amount' => 1000], ['account' => '2200', 'amount' => 150]],
            idempotencyKey: $key,
        );
    }

    public function test_posts_a_balanced_double_entry_journal(): void
    {
        $j = app(Ledger::class)->post($this->balancedEvent());

        $this->assertSame('posted', $j->status);
        $this->assertNotNull($j->journal_no);
        $this->assertEquals($j->lines()->sum('debit'), $j->lines()->sum('credit'));
        $this->assertEquals(1150.0, (float) $j->lines()->sum('debit'));
    }

    public function test_is_idempotent(): void
    {
        $ledger = app(Ledger::class);
        $a = $ledger->post($this->balancedEvent('idem'));
        $b = $ledger->post($this->balancedEvent('idem'));

        $this->assertSame($a->id, $b->id);
    }

    public function test_rejects_an_unbalanced_event(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FinancialEvent(
            sourceModule: 't', eventType: 'x',
            debits: [['account' => '1100', 'amount' => 100]],
            credits: [['account' => '4100', 'amount' => 90]],
        );
    }

    public function test_rejects_posting_into_a_closed_period(): void
    {
        FinFiscalPeriod::forDate(now(), null)->update(['status' => 'locked']);

        $this->expectException(PostingException::class);
        app(Ledger::class)->post($this->balancedEvent('closed'));
    }

    public function test_reverses_with_a_swapped_correcting_entry(): void
    {
        $ledger = app(Ledger::class);
        $j = $ledger->post($this->balancedEvent('to-reverse'));
        $rev = $ledger->reverse($j, 'oops');
        $j->refresh();

        $this->assertSame('reversed', $j->status);
        $this->assertSame($j->id, $rev->reverses_journal_id);
        $this->assertEquals($j->lines()->sum('credit'), $rev->lines()->sum('debit'));
    }

    public function test_blocks_editing_a_posted_journal(): void
    {
        $j = app(Ledger::class)->post($this->balancedEvent('immutable'));

        $this->expectException(RuntimeException::class);
        $j->description = 'hack';
        $j->save();
    }
}
