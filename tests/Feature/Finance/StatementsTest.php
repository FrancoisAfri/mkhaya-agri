<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\StatementsService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StatementsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    public function test_profit_loss_and_by_tracking_reports(): void
    {
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.sale',
            debits: [['account' => '1100', 'amount' => 1000]],
            credits: [['account' => '4100', 'amount' => 1000, 'dimensions' => ['tracking' => 'PROJ-A']]],
            eventDate: now(), idempotencyKey: 'stmt_sale',
        ));
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.exp',
            debits: [['account' => '6200', 'amount' => 300]],
            credits: [['account' => '1100', 'amount' => 300]],
            eventDate: now(), idempotencyKey: 'stmt_exp',
        ));

        $svc = app(StatementsService::class);
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $pl = $svc->report('profit_loss', $from, $to);
        $this->assertEquals(700.0, (float) $pl['totals']['amount']); // net profit 1000 - 300

        $bt = $svc->report('by_tracking', $from, $to);
        $proj = collect($bt['rows'])->firstWhere('tracking', 'PROJ-A');
        $this->assertNotNull($proj);
        $this->assertEquals(1000.0, (float) $proj['income']);
    }
}
