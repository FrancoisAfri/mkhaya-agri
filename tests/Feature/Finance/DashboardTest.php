<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\DashboardService;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    public function test_widgets_reflect_cash_and_net_worth_from_the_ledger(): void
    {
        FinBankAccount::create(['account_id' => FinAccount::where('code', '1100')->value('id'), 'name' => 'Bank', 'type' => 'bank', 'currency' => 'ZAR']);
        // Capital injected: Dr Bank 50 000 / Cr Owner's equity (3100) 50 000.
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.capital',
            debits: [['account' => '1100', 'amount' => 50000]], credits: [['account' => '3100', 'amount' => 50000]],
            eventDate: now(), idempotencyKey: 'dash_capital',
        ));

        $widgets = collect(app(DashboardService::class)->widgets())->keyBy('key');

        $this->assertEquals(50000.0, $widgets['cash_bank']['value']);
        $this->assertEquals(50000.0, $widgets['net_worth']['value']); // assets 50k − liabilities 0
        // Every widget carries a drill target.
        $this->assertTrue($widgets->every(fn ($w) => ! empty($w['drill']['route'])));
    }
}
