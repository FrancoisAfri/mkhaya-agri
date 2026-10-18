<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Services\QuoteService;
use App\Modules\Finance\Accounting\Services\SalesOrderService;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create(['company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open']);
        }
    }

    private function customer(): FinParty
    {
        return FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'S' . uniqid(), 'name' => 'Buyer']);
    }

    private function order(FinParty $party): FinSalesOrder
    {
        return app(SalesOrderService::class)->save([
            'party_id' => $party->id,
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Widgets', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 15]],
        ]);
    }

    public function test_deliver_updates_status_and_invoice_posts(): void
    {
        $order = $this->order($this->customer());
        $this->assertEquals('open', $order->status);
        $this->assertEquals(1150.0, (float) $order->total);

        $line = $order->lines->first();
        app(SalesOrderService::class)->deliver($order, [$line->id => 4]); // partial
        $this->assertEquals('part_delivered', $order->fresh()->status);
        $this->assertEquals(4.0, (float) $order->fresh()->lines->first()->qty_delivered);

        app(SalesOrderService::class)->deliver($order->fresh('lines'), [$line->id => 6]); // rest
        $this->assertEquals('delivered', $order->fresh()->status);

        $invoice = app(SalesOrderService::class)->invoice($order->fresh('lines'));
        $this->assertInstanceOf(FinInvoice::class, $invoice);
        $this->assertNotNull($invoice->journal_id);            // posted to GL
        $this->assertEquals(1150.0, (float) $invoice->total);
        $this->assertEquals('invoiced', $order->fresh()->status);
    }

    public function test_quote_to_order_links_and_copies_lines(): void
    {
        $party = $this->customer();
        $quote = app(QuoteService::class)->createQuote([
            'party_id' => $party->id,
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Job', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 15]],
        ]);

        $order = app(SalesOrderService::class)->createFromQuote($quote);

        $this->assertEquals($quote->id, $order->quote_id);
        $this->assertEquals($order->id, $quote->fresh()->sales_order_id);
        $this->assertEquals('accepted', $quote->fresh()->status);
        $this->assertEquals(575.0, (float) $order->total);
        $this->assertCount(1, $order->lines);
    }
}
