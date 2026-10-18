<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\BudgetService;
use App\Modules\Finance\Accounting\Services\Ledger;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BudgetVarianceTest extends TestCase
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

    public function test_variance_compares_budget_to_actual_gl_movement(): void
    {
        $year = (int) now()->year;
        // Actual: R12 000 rent expense posted this year (Dr 6200 / Cr 1100).
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.rent',
            debits: [['account' => '6200', 'amount' => 12000]], credits: [['account' => '1100', 'amount' => 12000]],
            eventDate: now(), idempotencyKey: 'budget_test_rent',
        ));

        $rentId = FinAccount::where('code', '6200')->value('id');
        $budget = app(BudgetService::class)->save([
            'name' => 'Ops ' . $year, 'fiscal_year' => $year, 'period_type' => 'annual',
            'lines' => [['account_id' => $rentId, 'period_no' => 0, 'amount' => 10000]], // budgeted R10 000
        ]);

        $v = app(BudgetService::class)->variance($budget, 12);
        $row = collect($v['rows'])->firstWhere('account_id', $rentId);

        $this->assertEquals(10000.0, $row['budget']);
        $this->assertEquals(12000.0, $row['actual']);
        $this->assertEquals(2000.0, $row['variance']);      // R2 000 over
        $this->assertFalse($row['favourable']);             // over-spend on an expense is unfavourable
    }
}
