<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Services\ExpenseService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseClaimTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '6300')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    public function test_approving_a_reimbursable_expense_posts_expense_vat_and_payable(): void
    {
        $fuel = FinAccount::where('code', '6300')->value('id');
        $svc = app(ExpenseService::class);
        $exp = $svc->create([
            'category' => 'Travel', 'description' => 'Fuel', 'expense_account_id' => $fuel,
            'amount' => 200, 'tax_amount' => 30, 'payment_method' => 'reimbursable', 'expense_date' => now()->toDateString(),
        ]);
        $this->assertEquals(230.0, (float) $exp->total);

        $svc->approve($exp);

        $j = $exp->fresh()->journal_id;
        $ap = FinAccount::where('code', '2100')->value('id');
        $vat = FinAccount::where('code', '1400')->value('id');
        $this->assertEquals('posted', $exp->fresh()->status);
        $this->assertEquals(200.0, (float) FinJournalLine::where('journal_id', $j)->where('account_id', $fuel)->value('debit'));
        $this->assertEquals(30.0, (float) FinJournalLine::where('journal_id', $j)->where('account_id', $vat)->value('debit'));
        $this->assertEquals(230.0, (float) FinJournalLine::where('journal_id', $j)->where('account_id', $ap)->value('credit'));
    }

    public function test_mileage_computes_amount_from_km_times_rate(): void
    {
        $fuel = FinAccount::where('code', '6300')->value('id');
        $exp = app(ExpenseService::class)->create([
            'expense_account_id' => $fuel, 'is_mileage' => true, 'km' => 100, 'rate' => 4.84,
            'payment_method' => 'reimbursable', 'expense_date' => now()->toDateString(),
        ]);
        $this->assertEquals(484.0, (float) $exp->amount);
        $this->assertEquals(484.0, (float) $exp->total);
    }
}
