<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Services\StaffLoanLedgerService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StaffLoanLedgerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        FinAccount::firstOrCreate(['code' => '1270', 'company_id' => null],
            ['name' => 'Staff Loans Receivable', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    public function test_disbursement_and_repayment_post_to_the_ledger(): void
    {
        $svc = app(StaffLoanLedgerService::class);
        $recv = FinAccount::where('code', '1270')->value('id');
        $bank = FinAccount::where('code', '1100')->value('id');

        $jd = $svc->disburse(101, 5000);
        $this->assertEquals(5000.0, (float) FinJournalLine::where('journal_id', $jd)->where('account_id', $recv)->value('debit'));
        $this->assertEquals(5000.0, (float) FinJournalLine::where('journal_id', $jd)->where('account_id', $bank)->value('credit'));

        $jr = $svc->repay(9001, 101, 1000);
        $this->assertEquals(1000.0, (float) FinJournalLine::where('journal_id', $jr)->where('account_id', $recv)->value('credit'));
        $this->assertEquals(1000.0, (float) FinJournalLine::where('journal_id', $jr)->where('account_id', $bank)->value('debit'));
    }
}
