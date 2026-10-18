<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\YearEndService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class YearEndTest extends TestCase
{
    use DatabaseTransactions;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = (int) now()->year;

        if (! FinAccount::where('code', '1100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        // Ensure the full year's periods exist (open) so the close can post at year-end.
        for ($p = 1; $p <= 12; $p++) {
            $exists = FinFiscalPeriod::whereNull('company_id')->where('fiscal_year', $this->year)->where('period_no', $p)->exists();
            if (! $exists) {
                $s = Carbon::create($this->year, $p, 1);
                FinFiscalPeriod::create([
                    'company_id' => null, 'fiscal_year' => $this->year, 'period_no' => $p,
                    'start_date' => $s->toDateString(), 'end_date' => $s->copy()->endOfMonth()->toDateString(), 'status' => 'open',
                ]);
            }
        }
    }

    private function entry(array $debits, array $credits): void
    {
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.ye',
            debits: $debits, credits: $credits,
            eventDate: now(), idempotencyKey: 'ye-' . uniqid('', true),
        ));
    }

    public function test_year_end_closes_pl_to_retained_earnings(): void
    {
        $this->entry([['account' => '1100', 'amount' => 1000]], [['account' => '4100', 'amount' => 1000]]); // sale
        $this->entry([['account' => '6100', 'amount' => 300]], [['account' => '1100', 'amount' => 300]]);    // expense

        $r = app(YearEndService::class)->close($this->year);

        $this->assertEquals(700.0, $r['net_profit']);
        $this->assertEquals(12, $r['next_periods_created']);
        $this->assertNotNull($r['journal_no']);

        $bal = app(FinanceReportService::class)->accountBalances(null, Carbon::create($this->year, 12, 31)->toDateString());
        $this->assertEquals(700.0, (float) $bal->firstWhere('code', '3100')['balance']); // retained earnings
        $this->assertEquals(0.0, round($bal->where('type', 'income')->sum('balance'), 2)); // P&L reset
        $this->assertEquals(0.0, round($bal->where('type', 'expense')->sum('balance'), 2));
    }

    public function test_year_end_is_idempotent(): void
    {
        $this->entry([['account' => '1100', 'amount' => 500]], [['account' => '4100', 'amount' => 500]]);

        $svc = app(YearEndService::class);
        $svc->close($this->year);
        $second = $svc->close($this->year);

        $this->assertTrue($second['already_closed']);
        $bal = app(FinanceReportService::class)->accountBalances(null, Carbon::create($this->year, 12, 31)->toDateString());
        $this->assertEquals(500.0, (float) $bal->firstWhere('code', '3100')['balance']); // not doubled
    }
}
