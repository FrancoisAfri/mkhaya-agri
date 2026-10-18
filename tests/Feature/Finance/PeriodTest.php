<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\PeriodService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PeriodTest extends TestCase
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

    private function entry(): void
    {
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.period',
            debits: [['account' => '1100', 'amount' => 10]],
            credits: [['account' => '4100', 'amount' => 10]],
            eventDate: now(), idempotencyKey: 'per-' . uniqid('', true),
        ));
    }

    public function test_soft_close_blocks_new_postings(): void
    {
        $period = FinFiscalPeriod::forDate(now(), null);
        app(PeriodService::class)->softClose($period, 'month end');

        $this->expectException(PostingException::class);
        $this->entry();
    }

    public function test_reopen_allows_posting_again(): void
    {
        $svc = app(PeriodService::class);
        $period = FinFiscalPeriod::forDate(now(), null);

        $svc->softClose($period);
        $svc->reopen($period->fresh(), 'correction needed');

        $this->entry();
        $this->assertTrue(true); // no exception — posting succeeded
    }

    public function test_hard_closed_period_still_takes_reversals_but_not_postings(): void
    {
        $svc = app(PeriodService::class);
        $period = FinFiscalPeriod::forDate(now(), null);

        $svc->hardClose($period, 'audited');

        $this->assertFalse($period->fresh()->acceptsPostings());
        $this->assertTrue($period->fresh()->acceptsReversals());
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $svc = app(PeriodService::class);
        $period = FinFiscalPeriod::forDate(now(), null);
        $svc->hardClose($period);
        $svc->lock($period->fresh(), 'year archived');

        $this->expectException(PostingException::class);
        $svc->reopen($period->fresh(), 'too late'); // locked cannot reopen
    }
}
