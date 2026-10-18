<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\TaxService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VatReturnTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '2200')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    public function test_vat201_nets_output_against_input_tax(): void
    {
        // A sale: Dr bank 1150 / Cr sales 1000 / Cr VAT output (2200) 150.
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.sale',
            debits: [['account' => '1100', 'amount' => 1150]],
            credits: [['account' => '4100', 'amount' => 1000], ['account' => '2200', 'amount' => 150]],
            eventDate: now(), idempotencyKey: 'vat_sale',
        ));
        // A purchase: Dr expense 400 / Dr VAT input (1400) 60 / Cr bank 460.
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.purchase',
            debits: [['account' => '6200', 'amount' => 400], ['account' => '1400', 'amount' => 60]],
            credits: [['account' => '1100', 'amount' => 460]],
            eventDate: now(), idempotencyKey: 'vat_purchase',
        ));

        $r = app(TaxService::class)->vat201(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        $this->assertEquals(150.0, $r['output_tax']);
        $this->assertEquals(60.0, $r['input_tax']);
        $this->assertEquals(90.0, $r['net']);         // 150 - 60 payable
        $this->assertTrue($r['is_payable']);
    }
}
