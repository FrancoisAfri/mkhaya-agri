<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\AgeingService;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ArApTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    private function party(string $kind = 'customer'): FinParty
    {
        return FinParty::create(['kind' => $kind, 'code' => 'P' . uniqid(), 'name' => 'Acme', 'credit_terms_days' => 30]);
    }

    private function glBalance(string $code): float
    {
        $row = app(FinanceReportService::class)->accountBalances(null, now()->toDateString())->firstWhere('code', $code);
        return round((float) ($row['balance'] ?? 0), 2);
    }

    public function test_sales_invoice_posts_ar_income_and_vat(): void
    {
        $svc = app(ArApService::class);
        $arBefore = $this->glBalance('1200');

        $inv = $svc->createInvoice([
            'party_id' => $this->party()->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Widgets', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);

        $this->assertEquals(1000.0, (float) $inv->subtotal);
        $this->assertEquals(150.0, (float) $inv->tax_total);
        $this->assertEquals(1150.0, (float) $inv->total);
        $this->assertNotNull($inv->journal_id);

        // GL: AR +1150, income +1000, VAT output +150.
        $this->assertEquals($arBefore + 1150, $this->glBalance('1200'));
        $this->assertEquals(150.0, $this->glBalance('2200'));
    }

    public function test_receipt_settles_invoice_and_reduces_ar(): void
    {
        $svc = app(ArApService::class);
        $party = $this->party();
        $inv = $svc->createInvoice([
            'party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Job', 'unit_price' => 500]],
        ]);
        $arAfterInvoice = $this->glBalance('1200');

        $svc->recordPayment([
            'party_id' => $party->id, 'direction' => 'receipt',
            'allocations' => [['invoice_id' => $inv->id, 'amount' => 500]],
        ]);

        $this->assertEquals('paid', $inv->fresh()->status);
        $this->assertEquals(500.0, (float) $inv->fresh()->amount_paid);
        $this->assertEquals($arAfterInvoice - 500, $this->glBalance('1200')); // AR cleared
    }

    public function test_supplier_bill_and_payment_posts_ap(): void
    {
        $svc = app(ArApService::class);
        $supplier = $this->party('supplier');
        $apBefore = $this->glBalance('2100');

        $bill = $svc->createInvoice([
            'party_id' => $supplier->id, 'direction' => 'payable',
            'lines' => [['account_id' => FinAccount::where('code', '6200')->value('id'), 'description' => 'Rent', 'unit_price' => 800]],
        ]);
        $this->assertEquals($apBefore + 800, $this->glBalance('2100'));

        $svc->recordPayment([
            'party_id' => $supplier->id, 'direction' => 'payment',
            'allocations' => [['invoice_id' => $bill->id, 'amount' => 800]],
        ]);
        $this->assertEquals('paid', $bill->fresh()->status);
        $this->assertEquals($apBefore, $this->glBalance('2100')); // AP cleared
    }

    public function test_ageing_ties_to_gl_control(): void
    {
        $svc = app(ArApService::class);
        $party = $this->party();
        $svc->createInvoice(['party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'A', 'unit_price' => 300]]]);
        $inv2 = $svc->createInvoice(['party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'B', 'unit_price' => 200]]]);
        $svc->recordPayment(['party_id' => $party->id, 'direction' => 'receipt',
            'allocations' => [['invoice_id' => $inv2->id, 'amount' => 200]]]);

        $ageing = app(AgeingService::class)->ageing('receivable', now()->toDateString());

        $this->assertTrue($ageing['ties'], 'AR ageing must tie to the GL control account.');
        $this->assertEquals($this->glBalance('1200'), $ageing['totals']['total']);
        $this->assertEquals($ageing['gl_balance'], $ageing['totals']['total']);
    }

    public function test_partial_payment_marks_part_paid(): void
    {
        $svc = app(ArApService::class);
        $party = $this->party();
        $inv = $svc->createInvoice(['party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'X', 'unit_price' => 1000]]]);

        $svc->recordPayment(['party_id' => $party->id, 'direction' => 'receipt',
            'allocations' => [['invoice_id' => $inv->id, 'amount' => 400]]]);

        $this->assertEquals('part_paid', $inv->fresh()->status);
        $this->assertEquals(600.0, $inv->fresh()->outstanding());
    }

    public function test_credit_note_reverses_and_reduces_the_invoice(): void
    {
        $svc = app(ArApService::class);
        $party = $this->party();
        $arBefore = $this->glBalance('1200');

        $inv = $svc->createInvoice([
            'party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Job', 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);
        $this->assertEquals($arBefore + 1150, $this->glBalance('1200'));

        $cn = $svc->createCreditNote($inv->fresh('lines'), 1150, 'Goods returned');

        $this->assertEquals('credit_note', $cn->doc_type);
        $this->assertNotNull($cn->journal_id);
        $this->assertEquals('paid', $inv->fresh()->status);
        $this->assertEquals(0.0, $inv->fresh()->outstanding());
        $this->assertEquals($arBefore, $this->glBalance('1200')); // AR cleared

        // Ageing still ties to the GL after the credit note.
        $ageing = app(\App\Modules\Finance\Accounting\Services\AgeingService::class)->ageing('receivable');
        $this->assertTrue($ageing['ties']);
    }

    public function test_debit_note_reverses_and_reduces_the_bill(): void
    {
        $svc = app(ArApService::class);
        $supplier = $this->party('supplier');
        $apBefore = $this->glBalance('2100');
        $expenseBefore = $this->glBalance('6200');

        $bill = $svc->createInvoice([
            'party_id' => $supplier->id, 'direction' => 'payable',
            'lines' => [['account_id' => FinAccount::where('code', '6200')->value('id'), 'description' => 'Rent', 'unit_price' => 800, 'tax_rate' => 15]],
        ]);
        $this->assertEquals($apBefore + 920, $this->glBalance('2100'));

        $svc->createDebitNote($bill->fresh('lines'), 920, 'Returned to supplier');

        $this->assertEquals('paid', $bill->fresh()->status);
        $this->assertEquals(0.0, $bill->fresh()->outstanding());
        $this->assertEquals($apBefore, $this->glBalance('2100'));      // AP cleared
        $this->assertEquals($expenseBefore, $this->glBalance('6200')); // expense reversed

        $ageing = app(\App\Modules\Finance\Accounting\Services\AgeingService::class)->ageing('payable');
        $this->assertTrue($ageing['ties']);
    }

    public function test_bad_debt_write_off_clears_ar_to_bad_debts(): void
    {
        $svc = app(ArApService::class);
        $party = $this->party();
        $arBefore = $this->glBalance('1200');
        $badDebtBefore = $this->glBalance('6800');

        $inv = $svc->createInvoice([
            'party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Job', 'unit_price' => 1000, 'tax_rate' => 15]],
        ]);

        $svc->writeOff($inv->fresh('lines'), 1150, 'Customer insolvent');

        $this->assertEquals('written_off', $inv->fresh()->status);
        $this->assertEquals(0.0, $inv->fresh()->outstanding());
        $this->assertEquals($arBefore, $this->glBalance('1200'));            // AR cleared
        $this->assertEquals($badDebtBefore + 1000, $this->glBalance('6800')); // net to bad debts (VAT reclaimed)

        $ageing = app(\App\Modules\Finance\Accounting\Services\AgeingService::class)->ageing('receivable');
        $this->assertTrue($ageing['ties']);
    }

    public function test_void_reverses_the_gl_journal(): void
    {
        $svc = app(ArApService::class);
        $party = $this->party();
        $arBefore = $this->glBalance('1200');
        $inv = $svc->createInvoice(['party_id' => $party->id, 'direction' => 'receivable',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Z', 'unit_price' => 700]]]);

        $svc->voidInvoice($inv);

        $this->assertEquals('void', $inv->fresh()->status);
        $this->assertEquals($arBefore, $this->glBalance('1200')); // reversed out
    }
}
