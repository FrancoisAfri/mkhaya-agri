<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Services\BankService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BankTransferTest extends TestCase
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

    private function banks(): array
    {
        $svc = app(BankService::class);
        $a = $svc->create(['account_id' => FinAccount::where('code', '1100')->value('id'), 'name' => 'Cheque', 'type' => 'bank', 'currency' => 'ZAR']);
        $b = $svc->create(['name' => 'Savings', 'type' => 'bank', 'currency' => 'ZAR']); // auto-creates GL
        return [$a, $b];
    }

    public function test_transfer_posts_dr_destination_cr_source(): void
    {
        [$from, $to] = $this->banks();

        $transfer = app(BankService::class)->transfer([
            'from_bank_id' => $from->id, 'to_bank_id' => $to->id, 'amount' => 500, 'transfer_date' => now()->toDateString(),
        ]);

        $this->assertNotNull($transfer->journal_id);
        $drTo = FinJournalLine::where('journal_id', $transfer->journal_id)->where('account_id', $to->account_id)->first();
        $crFrom = FinJournalLine::where('journal_id', $transfer->journal_id)->where('account_id', $from->account_id)->first();
        $this->assertEquals(500.0, (float) $drTo->debit);
        $this->assertEquals(500.0, (float) $crFrom->credit);
    }

    public function test_transfer_with_fee_expenses_the_charge(): void
    {
        [$from, $to] = $this->banks();
        $feeAccount = FinAccount::where('code', '6800')->value('id');

        $transfer = app(BankService::class)->transfer([
            'from_bank_id' => $from->id, 'to_bank_id' => $to->id, 'amount' => 500, 'fee' => 20, 'fee_account' => '6800',
        ]);

        // Source is credited amount + fee (520 across two credit lines); fee account debited 20.
        $creditsFromSource = FinJournalLine::where('journal_id', $transfer->journal_id)->where('account_id', $from->account_id)->sum('credit');
        $this->assertEquals(520.0, (float) $creditsFromSource);
        $feeLine = FinJournalLine::where('journal_id', $transfer->journal_id)->where('account_id', $feeAccount)->first();
        $this->assertEquals(20.0, (float) $feeLine->debit);
    }

    public function test_same_account_is_rejected(): void
    {
        [$from] = $this->banks();
        $this->expectException(\App\Modules\Finance\Accounting\Exceptions\PostingException::class);
        app(BankService::class)->transfer(['from_bank_id' => $from->id, 'to_bank_id' => $from->id, 'amount' => 100]);
    }
}
