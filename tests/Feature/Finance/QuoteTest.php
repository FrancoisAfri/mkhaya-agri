<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\QuoteService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    private function customer(): FinParty
    {
        return FinParty::create(['kind' => 'customer', 'code' => 'C' . uniqid(), 'name' => 'Beta']);
    }

    private function item(): FinItem
    {
        return FinItem::create([
            'code' => 'IT' . uniqid(), 'name' => 'Consulting', 'unit' => 'hour', 'type' => 'service',
            'sales_price' => 500, 'cost_price' => 0, 'tax_rate' => 15,
            'sales_account_id' => FinAccount::where('code', '4200')->value('id'),
        ]);
    }

    public function test_quote_computes_totals_without_touching_gl(): void
    {
        $item = $this->item();
        $quote = app(QuoteService::class)->createQuote([
            'party_id' => $this->customer()->id,
            'lines' => [['account_id' => $item->sales_account_id, 'item_id' => $item->id, 'description' => 'Advisory', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 15]],
        ]);

        $this->assertEquals('draft', $quote->status);
        $this->assertEquals(1000.0, (float) $quote->subtotal);
        $this->assertEquals(150.0, (float) $quote->tax_total);
        $this->assertEquals(1150.0, (float) $quote->total);
        $this->assertNull($quote->invoice_id);
        $this->assertEquals($item->id, $quote->lines->first()->item_id);
    }

    public function test_document_discount_applies_to_totals(): void
    {
        $item = $this->item();
        $quote = app(QuoteService::class)->createQuote([
            'party_id' => $this->customer()->id, 'discount_percent' => 10,
            'lines' => [['account_id' => $item->sales_account_id, 'item_id' => $item->id, 'description' => 'X', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 15]],
        ]);

        $this->assertEquals(500.0, (float) $quote->subtotal);        // gross
        $this->assertEquals(50.0, (float) $quote->discount_amount);  // 10%
        $this->assertEquals(67.5, (float) $quote->tax_total);        // 75 × (450/500)
        $this->assertEquals(517.5, (float) $quote->total);           // 450 + 67.5
    }

    public function test_convert_creates_a_posted_invoice(): void
    {
        $item = $this->item();
        $svc = app(QuoteService::class);
        $quote = $svc->createQuote([
            'party_id' => $this->customer()->id,
            'lines' => [['account_id' => $item->sales_account_id, 'item_id' => $item->id, 'description' => 'Advisory', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 15]],
        ]);

        $invoice = $svc->convertToInvoice($quote->fresh('lines'));

        $this->assertInstanceOf(FinInvoice::class, $invoice);
        $this->assertNotNull($invoice->journal_id);              // posted to GL
        $this->assertEquals(575.0, (float) $invoice->total);     // 500 + 15%
        $this->assertEquals('invoiced', $quote->fresh()->status);
        $this->assertEquals($item->id, $invoice->lines->first()->item_id); // item carried through
    }

    public function test_convert_is_idempotent(): void
    {
        $item = $this->item();
        $svc = app(QuoteService::class);
        $quote = $svc->createQuote([
            'party_id' => $this->customer()->id,
            'lines' => [['account_id' => $item->sales_account_id, 'item_id' => $item->id, 'description' => 'X', 'unit_price' => 100, 'tax_rate' => 0]],
        ]);

        $first = $svc->convertToInvoice($quote->fresh('lines'));
        $second = $svc->convertToInvoice($quote->fresh('lines'));

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, FinInvoice::where('id', $first->id)->count());
    }
}
