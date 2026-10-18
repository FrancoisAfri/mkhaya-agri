<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\ReportBuilderService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '6200')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    public function test_gl_report_groups_by_account_type_and_sums_net(): void
    {
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.exp',
            debits: [['account' => '6200', 'amount' => 900]], credits: [['account' => '1100', 'amount' => 900]],
            eventDate: now(), idempotencyKey: 'rb_exp',
        ));

        $out = app(ReportBuilderService::class)->run([
            'source' => 'gl', 'dimensions' => ['account_type'], 'measures' => ['net'], 'filters' => [],
        ]);

        $this->assertNotEmpty($out['rows']);
        $expense = collect($out['rows'])->firstWhere('account_type', 'expense');
        $this->assertEquals(900.0, (float) $expense['net']);
        // Whitelisting: an unknown dimension is ignored, not injected.
        $safe = app(ReportBuilderService::class)->run(['source' => 'gl', 'dimensions' => ['x; DROP TABLE'], 'measures' => ['net'], 'filters' => []]);
        $this->assertIsArray($safe['rows']);
    }
}
