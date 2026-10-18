<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Services\ExternalImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sage's account list: no code column and no ledger type — accounts are identified by name and
 * grouped by category. The import has to derive the type, number the account, and be re-runnable.
 */
class SageAccountsImportTest extends TestCase
{
    use DatabaseTransactions;

    private const HEADERS = ['Name', 'Category', 'Description', 'Opening Balance', 'Opening Balance Date', 'Active', 'Default VAT Type'];

    private function csv(array ...$rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'coa') . '.csv';
        $out = fopen($path, 'w');
        fwrite($out, "sep=,\n");           // Sage writes this above the header
        fputcsv($out, self::HEADERS);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        return new UploadedFile($path, 'Accounts.csv', null, null, true);
    }

    private function import(UploadedFile $file): array
    {
        return app(ExternalImportService::class)->import($file, 'sage', 'accounts');
    }

    public function test_it_types_and_numbers_accounts_off_the_category(): void
    {
        $stem = Str::random(6);
        $this->import($this->csv(
            ["Accounting Fees {$stem}", 'Expenses', '', '13914.7700', '18/07/2026', 'True', 'No VAT'],
            ["Bank Loans {$stem}", 'Non-Current Liabilities', '', '-0.3400', '18/07/2026', 'True', 'No VAT'],
            ["Sales {$stem}", 'Sales', '', '0.0000', '18/07/2026', 'True', 'No VAT'],
            ["Equipment {$stem}", 'Non-Current Assets', '', '0.0000', '18/07/2026', 'True', 'No Default'],
            ["Capital {$stem}", 'Owners Equity', '', '0.0000', '18/07/2026', 'True', 'No VAT'],
            ["Cost of Flights {$stem}", 'Cost of Sales', '', '0.0000', '18/07/2026', 'False', 'No VAT'],
        ));

        $expected = [
            "Accounting Fees {$stem}"  => ['expense', 'debit', '6'],
            "Bank Loans {$stem}"       => ['liability', 'credit', '2'],
            "Sales {$stem}"            => ['income', 'credit', '4'],
            "Equipment {$stem}"        => ['asset', 'debit', '1'],
            "Capital {$stem}"          => ['equity', 'credit', '3'],
            "Cost of Flights {$stem}"  => ['expense', 'debit', '6'],
        ];

        foreach ($expected as $name => [$type, $normal, $band]) {
            $account = FinAccount::where('name', $name)->firstOrFail();
            $this->assertSame($type, $account->type, $name);
            $this->assertSame($normal, $account->normal_balance, $name);
            $this->assertSame($band, substr($account->code, 0, 1), "{$name} numbered outside its band");
            $this->assertTrue((bool) $account->is_postable, $name);
        }

        // The category is kept, so cost of sales stays separable from other overheads.
        $this->assertSame('Cost of Sales', FinAccount::where('name', "Cost of Flights {$stem}")->value('subtype'));
        $this->assertSame('No VAT', FinAccount::where('name', "Sales {$stem}")->value('tax_code'));
        $this->assertFalse((bool) FinAccount::where('name', "Cost of Flights {$stem}")->value('is_active'));
    }

    public function test_re_running_updates_instead_of_renumbering(): void
    {
        $stem = Str::random(6);
        $row = ["Advertising {$stem}", 'Expenses', '', '98974.0400', '18/07/2026', 'True', 'No VAT'];

        $first = $this->import($this->csv($row));
        $code = FinAccount::where('name', "Advertising {$stem}")->value('code');

        // Same account, recategorised in Sage since the first run.
        $second = $this->import($this->csv(["Advertising {$stem}", 'Cost of Sales', '', '0.0000', '18/07/2026', 'True', 'No VAT']));

        $this->assertSame(1, $first['imported']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, FinAccount::where('name', "Advertising {$stem}")->count());
        $this->assertSame($code, FinAccount::where('name', "Advertising {$stem}")->value('code'));  // code never churns
        $this->assertSame('Cost of Sales', FinAccount::where('name', "Advertising {$stem}")->value('subtype'));
    }

    public function test_generated_codes_do_not_collide(): void
    {
        $stem = Str::random(6);
        $rows = [];
        foreach (range(1, 12) as $n) {
            $rows[] = ["Expense {$stem} {$n}", 'Expenses', '', '0.0000', '18/07/2026', 'True', 'No VAT'];
        }
        $this->import($this->csv(...$rows));

        $codes = FinAccount::where('name', 'like', "Expense {$stem} %")->pluck('code');
        $this->assertCount(12, $codes);
        $this->assertCount(12, array_unique($codes->all()));
    }

    public function test_an_untypeable_row_is_reported_not_guessed(): void
    {
        $result = $this->import($this->csv(['Mystery ' . Str::random(6), 'Wibble', '', '0.0000', '18/07/2026', 'True', 'No VAT']));

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Wibble', $result['skipped'][0]['reason']);
    }

    public function test_opening_balances_are_read_but_never_posted(): void
    {
        $stem = Str::random(6);
        $preview = app(ExternalImportService::class)->preview(
            $this->csv(["Accounting Fees {$stem}", 'Expenses', '', '13914.7700', '18/07/2026', 'True', 'No VAT']),
            'sage',
            'accounts',
        );

        // Read and shown, so nothing looks silently dropped…
        $this->assertSame('Opening Balance', $preview['mapped']['opening_balance']);
        $this->assertSame('13914.7700', $preview['sample'][0]['opening_balance']);

        // …but the account lands with no journal behind it.
        $this->import($this->csv(["Accounting Fees {$stem}", 'Expenses', '', '13914.7700', '18/07/2026', 'True', 'No VAT']));
        $account = FinAccount::where('name', "Accounting Fees {$stem}")->firstOrFail();
        $this->assertSame(0, \DB::table('fin_journal_lines')->where('account_id', $account->id)->count());
    }
}
