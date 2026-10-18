<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPayment;
use App\Modules\Finance\Accounting\Services\ExternalImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sage's Customer Transactions Report replayed as the debtors book. The file is a report, not a
 * table: the customer's name sits alone in the date column above its block, and Opening/Closing/
 * Movement lines are totals. Invoices must come back as invoices and receipts must settle them,
 * otherwise the ageing shows everything as still owing.
 */
class SageCustomerLedgerImportTest extends TestCase
{
    use DatabaseTransactions;

    /** The header Sage writes: the customer name and the date share one column. */
    private const HEADERS = ["Customer \n                            Date", 'Reference', 'Transaction Type', 'Description', 'Debit', 'Credit', 'Balance'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedChartOfAccounts();
    }

    /** The control accounts every AR posting needs, if this environment has no chart yet. */
    private function seedChartOfAccounts(): void
    {
        $accounts = [
            [config('finance.ar_control', '1200'), 'Accounts Receivable', 'asset', 'debit'],
            [config('finance.vat_output', '2200'), 'VAT Output', 'liability', 'credit'],
            [config('finance.default_bank', '1100'), 'Bank', 'asset', 'debit'],
            [config('finance.retained_earnings_account', '3100'), 'Opening Balances', 'equity', 'credit'],
            ['4900', 'Sales', 'income', 'credit'],
        ];
        foreach ($accounts as [$code, $name, $type, $normal]) {
            FinAccount::firstOrCreate(
                ['company_id' => null, 'code' => $code],
                ['name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_postable' => true, 'is_active' => true],
            );
        }
    }

    private function csv(array ...$rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ledger') . '.csv';
        $out = fopen($path, 'w');
        fwrite($out, "sep=,\n");
        fputcsv($out, self::HEADERS);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        return new UploadedFile($path, 'CustomerTransactionsReport.csv', null, null, true);
    }

    private function import(UploadedFile $file): array
    {
        return app(ExternalImportService::class)->import($file, 'sage', 'customer_transactions');
    }

    /** What the customer owes: open invoices less open credit notes, the way a statement reads. */
    private function outstanding(string $customer): float
    {
        $party = FinParty::where('name', $customer)->firstOrFail();

        return round((float) FinInvoice::where('party_id', $party->id)->get()
            ->sum(fn (FinInvoice $i) => ($i->doc_type === 'credit_note' ? -1 : 1) * (float) $i->outstanding()), 2);
    }

    public function test_the_customer_column_is_read_and_totals_are_not_treated_as_transactions(): void
    {
        $name = 'Africa Legacy ' . Str::random(6);
        $ref = 'INV' . random_int(1000000, 9999999);

        $result = $this->import($this->csv(
            [$name, '', '', '', '', '', ''],
            ['Opening Balance as at:  03/01/2022', '', '', '', '0', '', ''],
            ['18/10/2023', $ref, 'Tax Invoice', '', '6500', '', '6500'],
            ['Closing Balance as at:  31/07/2026', '', '', '', '', '6500', ''],
            ['Movement for the period', '', '', '', '', '6500', ''],
        ));

        $this->assertSame(1, $result['imported'], 'only the invoice row should post');
        $this->assertSame([], $result['skipped']);
        $invoice = FinInvoice::where('party_ref', $ref)->firstOrFail();
        $this->assertSame('receivable', $invoice->direction);
        $this->assertEquals(6500.0, (float) $invoice->total);
        $this->assertSame($name, FinParty::find($invoice->party_id)->name);
    }

    public function test_receipts_settle_the_invoices_they_follow(): void
    {
        $name = 'Settled Co ' . Str::random(6);
        $inv = 'INV' . random_int(1000000, 9999999);
        $rcp = 'RCP' . random_int(1000000, 9999999);

        $this->import($this->csv(
            [$name, '', '', '', '', '', ''],
            ['18/10/2023', $inv, 'Tax Invoice', '', '6500', '', '6500'],
            ['19/10/2023', $rcp, 'Customer Receipt', 'FNB APP PAYMENT', '', '4450', '2050'],
            ['27/10/2023', 'RCP' . random_int(1000000, 9999999), 'Customer Receipt', '', '', '2050', '0'],
        ));

        // Invoice raised at 6500 and fully settled by the two receipts — nothing left owing.
        $invoice = FinInvoice::where('party_ref', $inv)->firstOrFail();
        $this->assertEquals(6500.0, (float) $invoice->total);
        $this->assertEquals(6500.0, (float) $invoice->amount_paid);
        $this->assertSame('paid', $invoice->status);
        $this->assertEqualsWithDelta(0.0, $this->outstanding($name), 0.01);

        // The receipt is a real payment, referenced by its Sage number, against the clearing account.
        $payment = FinPayment::where('reference', $rcp)->firstOrFail();
        $this->assertSame('receipt', $payment->direction);
        $this->assertEquals(4450.0, (float) $payment->amount);
        $this->assertSame(
            (int) FinAccount::where('code', config('finance.migration_clearing', '9998'))->value('id'),
            (int) $payment->bank_account_id,
        );
    }

    public function test_a_receipt_with_nothing_open_is_carried_as_unallocated_credit(): void
    {
        $name = 'Paid Ahead ' . Str::random(6);
        $rcp = 'RCP' . random_int(1000000, 9999999);

        // 26 rows in the real file look like this — cash before any invoice we can see.
        $result = $this->import($this->csv(
            [$name, '', '', '', '', '', ''],
            ['05/01/2023', $rcp, 'Customer Receipt', 'E-MUNICIPALITY', '', '3600', '-3600'],
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['skipped']);
        $this->assertNull(FinPayment::where('reference', $rcp)->first(), 'nothing to allocate against');
        // The customer sits in credit by the full amount, as Sage had them.
        $this->assertEqualsWithDelta(-3600.0, $this->outstanding($name), 0.01);
    }

    public function test_credit_notes_reduce_the_balance_and_a_re_run_double_counts_nothing(): void
    {
        $name = 'Afrigrowth ' . Str::random(6);
        $inv = 'INV' . random_int(1000000, 9999999);
        $crn = 'CRN' . random_int(1000000, 9999999);

        $file = fn () => $this->csv(
            [$name, '', '', '', '', '', ''],
            ['31/05/2023', $inv, 'Tax Invoice', '', '20000', '', '20000'],
            ['11/07/2023', $crn, 'Credit Note', '', '', '20000', '0'],
        );

        $first = $this->import($file());
        $this->assertSame(2, $first['imported']);
        $this->assertEqualsWithDelta(0.0, $this->outstanding($name), 0.01);

        $second = $this->import($file());
        $this->assertSame(0, $second['imported']);
        $this->assertCount(2, $second['skipped']);
        $this->assertSame('Already imported.', $second['skipped'][0]['reason']);
        $this->assertEqualsWithDelta(0.0, $this->outstanding($name), 0.01);
    }

    public function test_a_non_zero_opening_balance_becomes_an_opening_invoice(): void
    {
        $name = 'Owed From Before ' . Str::random(6);

        $this->import($this->csv(
            [$name, '', '', '', '', '', ''],
            ['Opening Balance as at:  03/01/2022', '', '', '', '24250', '', '24250'],
        ));

        $this->assertEqualsWithDelta(24250.0, $this->outstanding($name), 0.01);
    }
}
