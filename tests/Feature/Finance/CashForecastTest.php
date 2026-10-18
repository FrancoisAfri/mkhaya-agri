<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\CashFlowForecastService;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashForecastTest extends TestCase
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

    private function invoice(string $direction, float $total, int $dueInDays): void
    {
        $party = FinParty::create(['kind' => $direction === 'receivable' ? 'customer' : 'supplier', 'source' => 'manual', 'code' => 'P' . uniqid(), 'name' => 'Party']);
        FinInvoice::create([
            'uuid' => (string) Str::uuid(), 'party_id' => $party->id, 'direction' => $direction, 'doc_type' => 'invoice',
            'number' => 'INV-' . uniqid(), 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays($dueInDays)->toDateString(),
            'currency' => 'ZAR', 'status' => 'open', 'subtotal' => $total, 'tax_total' => 0, 'total' => $total, 'amount_paid' => 0,
        ]);
    }

    public function test_forecast_projects_from_cash_plus_receivables_minus_payables(): void
    {
        // A bank account holding R10 000 (Dr bank / Cr sales as the opening move).
        $bank = FinBankAccount::create(['account_id' => FinAccount::where('code', '1100')->value('id'), 'name' => 'Cheque', 'type' => 'bank', 'currency' => 'ZAR']);
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.opening',
            debits: [['account' => '1100', 'amount' => 10000]], credits: [['account' => '4100', 'amount' => 10000]],
            eventDate: now(), idempotencyKey: 'cf_open:' . $bank->id,
        ));

        $this->invoice('receivable', 3000, 10); // money in, ~week 1
        $this->invoice('payable', 1000, 3);      // money out, week 0

        $f = app(CashFlowForecastService::class)->forecast(4);

        $this->assertEquals(10000.0, $f['opening']);
        $this->assertEquals(3000.0, $f['summary']['total_in']);
        $this->assertEquals(1000.0, $f['summary']['total_out']);
        $this->assertEquals(12000.0, $f['summary']['closing']);   // 10000 - 1000 + 3000
        $this->assertEquals(1000.0, $f['weeks'][0]['outflow']);
        $this->assertFalse($f['summary']['goes_negative']);
    }

    public function test_overdue_items_land_in_week_one_and_flag_negative(): void
    {
        FinBankAccount::create(['account_id' => FinAccount::where('code', '1100')->value('id'), 'name' => 'Cheque', 'type' => 'bank', 'currency' => 'ZAR']);
        // No opening cash; a large overdue payable → negative projection.
        $this->invoice('payable', 5000, -14); // overdue by two weeks

        $f = app(CashFlowForecastService::class)->forecast(6);

        $this->assertEquals(0.0, $f['opening']);
        $this->assertEquals(5000.0, $f['weeks'][0]['outflow']);   // overdue → week 1
        $this->assertTrue($f['summary']['goes_negative']);
        $this->assertEquals(-5000.0, $f['summary']['lowest']['balance']);
    }
}
