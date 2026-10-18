<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReportsTest extends TestCase
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

    private function entry(float $amount, string $debit, string $credit): void
    {
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.rpt',
            debits: [['account' => $debit, 'amount' => $amount]],
            credits: [['account' => $credit, 'amount' => $amount]],
            eventDate: now(), idempotencyKey: 'rpt-' . uniqid('', true),
        ));
    }

    public function test_account_ledger_runs_a_running_balance(): void
    {
        $this->entry(100, '1100', '4100');
        $this->entry(50, '1100', '4100');

        $bankId = FinAccount::where('code', '1100')->value('id');
        $ledger = app(FinanceReportService::class)->accountLedger(
            $bankId, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()
        );

        $this->assertEquals(0.0, $ledger['opening']);
        $this->assertCount(2, $ledger['rows']);
        $this->assertEquals(100.0, $ledger['rows'][0]['balance']);
        $this->assertEquals(150.0, $ledger['rows'][1]['balance']); // running balance
        $this->assertEquals(150.0, $ledger['closing']);
        $this->assertEquals(150.0, $ledger['debit_total']);
    }

    public function test_cash_flow_reconciles_to_cash_movement(): void
    {
        // Cash sale (bank up) + buy equipment (bank down): net cash +600.
        $this->entry(1000, '1100', '4100'); // sale
        $this->entry(400, '1510', '1100');  // buy equipment

        $cf = app(FinanceReportService::class)->cashFlow(
            null, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()
        );

        $this->assertTrue($cf['reconciles']);
        $this->assertEquals(600.0, $cf['net_change']);
        $this->assertEquals(round($cf['closing_cash'] - $cf['opening_cash'], 2), $cf['net_change']);
        // Equipment purchase lands in investing.
        $this->assertEquals(-400.0, $cf['investing_total']);
    }
}
