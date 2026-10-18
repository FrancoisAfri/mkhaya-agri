<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBankMatch;
use App\Modules\Finance\Accounting\Models\FinBankStatementLine;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\ReconciliationService;
use App\Modules\Finance\Accounting\Services\StatementImportService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BankReconcileTest extends TestCase
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

    private function bank(): FinBankAccount
    {
        return FinBankAccount::create([
            'account_id' => FinAccount::where('code', '1100')->value('id'),
            'name' => 'Test Cheque', 'type' => 'bank', 'currency' => 'ZAR',
        ]);
    }

    public function test_import_dedups_and_auto_match_reconciles_by_amount_and_date(): void
    {
        $bank = $this->bank();
        $today = now()->toDateString();

        // A recorded receipt in the ledger: Dr Bank 1500 / Cr Sales 1500 → a +1500 book entry.
        app(Ledger::class)->post(new FinancialEvent(
            sourceModule: 'test', eventType: 'test.receipt',
            debits: [['account' => '1100', 'amount' => 1500]],
            credits: [['account' => '4100', 'amount' => 1500]],
            eventDate: now(), idempotencyKey: 'bank_test_receipt:' . $bank->id,
        ));

        // Statement with the matching +1500 line, plus a duplicate row.
        $csv = "date,description,reference,amount\n"
            . "{$today},Customer deposit,INV001,1500.00\n"
            . "{$today},Customer deposit,INV001,1500.00\n";      // exact dup → skipped
        $file = $this->csv($csv);

        $result = app(StatementImportService::class)->import($bank, $file);
        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertEquals(1, FinBankStatementLine::where('bank_account_id', $bank->id)->count());

        $matched = app(ReconciliationService::class)->autoMatch($bank);
        $this->assertEquals(1, $matched);

        $line = FinBankStatementLine::where('bank_account_id', $bank->id)->first();
        $this->assertEquals('matched', $line->status);
        $this->assertEquals(1, FinBankMatch::where('statement_line_id', $line->id)->count());
        $this->assertEquals(0, app(ReconciliationService::class)->summary($bank)['unmatched_statement']);
    }

    public function test_create_entry_posts_contra_and_reconciles(): void
    {
        $bank = $this->bank();
        $line = FinBankStatementLine::create([
            'bank_account_id' => $bank->id, 'statement_date' => now(), 'description' => 'Monthly bank charge',
            'amount' => -60.00, 'hash' => 'chg' . uniqid(), 'status' => 'unmatched',
        ]);

        $match = app(ReconciliationService::class)->createEntry($line, $bank, '6800', 'Bank charge');

        $this->assertInstanceOf(FinBankMatch::class, $match);
        $this->assertEquals('matched', $line->fresh()->status);

        // The posted journal debits the contra (expense) and credits the bank for R60.
        $bankLine = \App\Modules\Finance\Accounting\Models\FinJournalLine::find($match->journal_line_id);
        $this->assertEquals($bank->account_id, $bankLine->account_id);
        $this->assertEquals(60.0, (float) $bankLine->credit);   // money out of the bank
        $this->assertEquals(0, app(ReconciliationService::class)->summary($bank)['unmatched_statement']);
    }

    public function test_allocate_to_supplier_posts_against_ap_control(): void
    {
        $bank = $this->bank();
        $supplier = FinParty::create(['kind' => 'supplier', 'source' => 'manual', 'code' => 'V' . uniqid(), 'name' => 'Acme Supplies', 'is_active' => true]);
        $line = FinBankStatementLine::create([
            'bank_account_id' => $bank->id, 'statement_date' => now(), 'description' => 'EFT to Acme',
            'amount' => -250.00, 'hash' => 'sup' . uniqid(), 'status' => 'unmatched',
        ]);

        app(ReconciliationService::class)->allocate($line, $bank, ['type' => 'supplier', 'party_id' => $supplier->id]);

        $this->assertEquals('matched', $line->fresh()->status);
        // AP control (2100) debited R250, bank (1100) credited R250.
        $ap = FinAccount::where('code', config('finance.ap_control'))->value('id');
        $apLine = \App\Modules\Finance\Accounting\Models\FinJournalLine::where('account_id', $ap)->latest('id')->first();
        $this->assertEquals(250.0, (float) $apLine->debit);
    }

    public function test_apply_rules_auto_allocates_matching_lines(): void
    {
        $bank = $this->bank();
        \App\Modules\Finance\Accounting\Models\FinBankRule::create([
            'name' => 'Bank charges', 'match_type' => 'contains', 'match_value' => 'charge', 'direction' => 'out',
            'allocation_type' => 'account', 'contra_account_id' => FinAccount::where('code', '6800')->value('id'), 'is_active' => true,
        ]);
        FinBankStatementLine::create(['bank_account_id' => $bank->id, 'statement_date' => now(), 'description' => 'Monthly service CHARGE', 'amount' => -45.00, 'hash' => 'r1' . uniqid(), 'status' => 'unmatched']);
        FinBankStatementLine::create(['bank_account_id' => $bank->id, 'statement_date' => now(), 'description' => 'Salary deposit', 'amount' => 5000.00, 'hash' => 'r2' . uniqid(), 'status' => 'unmatched']);

        $count = app(ReconciliationService::class)->applyRules($bank);

        $this->assertEquals(1, $count); // only the "charge" line matched
        $this->assertEquals(1, $bank->statementLines()->where('status', 'unmatched')->count());
    }

    private function csv(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt') . '.csv';
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'statement.csv', 'text/csv', null, true);
    }
}
