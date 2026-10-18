<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\DebtorsService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DebtorsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    private function overdueInvoice(): \App\Modules\Finance\Accounting\Models\FinInvoice
    {
        $party = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'D' . uniqid(), 'name' => 'Late Payer', 'email' => 'late@x.io']);

        return app(ArApService::class)->createInvoice([
            'party_id' => $party->id, 'direction' => 'receivable',
            'invoice_date' => now()->subDays(60), 'due_date' => now()->subDays(45),
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Job', 'unit_price' => 1000, 'tax_rate' => 0]],
        ]);
    }

    public function test_outstanding_lists_overdue_with_bucket(): void
    {
        $inv = $this->overdueInvoice();
        $out = app(DebtorsService::class)->outstanding(['overdue_only' => true]);

        $row = collect($out['rows'])->firstWhere('id', $inv->id);
        $this->assertNotNull($row);
        $this->assertEquals('31-60', $row['bucket']);       // 45 days overdue
        $this->assertGreaterThan(0, $row['days_overdue']);
        $this->assertEquals(1000.0, $row['outstanding']);
        // The roll-up lives under `kpis`, alongside total_outstanding and the ageing bands.
        $this->assertEquals($out['kpis']['overdue_outstanding'], collect($out['rows'])->sum('outstanding'));
    }

    public function test_log_promise_and_timeline(): void
    {
        $inv = $this->overdueInvoice();
        $svc = app(DebtorsService::class);

        $svc->logActivity($inv, ['type' => 'promise', 'promised_date' => now()->addDays(7)->toDateString(), 'promised_amount' => 1000, 'body' => 'Will pay Friday']);

        $out = app(DebtorsService::class)->outstanding();
        $row = collect($out['rows'])->firstWhere('id', $inv->id);
        $this->assertNotNull($row['promise']);
        $this->assertEquals(1000.0, $row['promise']['amount']);

        $timeline = $svc->timeline($inv);
        $this->assertCount(1, $timeline);
        $this->assertEquals('promise', $timeline[0]['type']);
    }
}
