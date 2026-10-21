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

    public function test_an_order_is_invoiced_delivery_by_delivery_for_what_the_customer_accepted_on_its_dimensions(): void
    {
        $party = $this->customer();
        $order = app(SalesOrderService::class)->save(['party_id' => $party->id, 'dimensions' => ['tracking' => 'NORTH', 'site' => '7'],
            'deliver_on' => today()->addDay()->toDateString(), 'delivery_address' => 'Dock 4',
            'lines' => [['account_id' => FinAccount::where('code', '4100')->value('id'), 'description' => 'Apples', 'grade' => 'Class 1', 'unit' => 'ctn',
                'quantity' => 50, 'unit_price' => 180, 'tax_rate' => 0, 'price_basis' => 'Customer list']]]);
        $line = $order->lines->first();
        $this->assertSame(['Class 1', 'ctn', 'Customer list'], [$line->grade, $line->unit, $line->price_basis]);

        // First load: 30 sent, 28 accepted, 2 turned away. Only the 28 are invoiced.
        $dn = app(SalesOrderService::class)->deliver($order, [$line->id => 30], 'Load 1', [$line->id => ['accepted' => 28, 'rejected' => 2]]);
        $inv = app(SalesOrderService::class)->invoiceDelivery($dn);
        $this->assertEqualsWithDelta(28 * 180.0, (float) $inv->total, 0.01);
        $this->assertEquals(['tracking' => 'NORTH', 'site' => '7'], $inv->dimensions);
        $this->assertSame($inv->id, (int) $dn->fresh()->invoice_id);
        $this->assertSame($inv->id, app(SalesOrderService::class)->invoiceDelivery($dn->fresh())->id, 'A delivery is invoiced once.');
        $this->assertSame('part_delivered', $order->fresh()->status);

        // Invoicing the whole order now would bill the first load twice.
        try {
            app(SalesOrderService::class)->invoice($order->fresh('lines'));
            $this->fail('A whole-order invoice after a delivery invoice must be refused.');
        } catch (\App\Modules\Finance\Accounting\Exceptions\PostingException) {
        }

        // More accepted and rejected than was sent is refused; the last 20 arrive and are all taken.
        try {
            app(SalesOrderService::class)->deliver($order->fresh('lines'), [$line->id => 20], null, [$line->id => ['accepted' => 20, 'rejected' => 1]]);
            $this->fail('Accepted plus rejected cannot exceed what was sent.');
        } catch (\App\Modules\Finance\Accounting\Exceptions\PostingException) {
        }
        $dn2 = app(SalesOrderService::class)->deliver($order->fresh('lines'), [$line->id => 20]);
        app(SalesOrderService::class)->invoiceDelivery($dn2);
        $l = $line->fresh();
        $this->assertEquals([50.0, 48.0, 2.0, 48.0], [(float) $l->qty_delivered, (float) $l->qty_accepted, (float) $l->qty_rejected, (float) $l->qty_invoiced]);
        $this->assertSame('invoiced', $order->fresh()->status);
    }

    public function test_a_price_comes_from_the_customers_own_dated_list_then_the_general_one_and_says_which(): void
    {
        $party = $this->customer();
        $general = \App\Modules\Finance\Accounting\Models\FinPriceList::create(['name' => 'General', 'valid_from' => today()->subMonth()]);
        $general->items()->create(['description' => 'Maize', 'unit' => 'kg', 'price' => 4.10]);
        $own = \App\Modules\Finance\Accounting\Models\FinPriceList::create(['name' => 'Buyer 2026', 'party_id' => $party->id, 'valid_from' => today()->subWeek(), 'valid_to' => today()->addMonth()]);
        $own->items()->create(['description' => 'Maize', 'grade' => 'WM1', 'unit' => 'kg', 'price' => 4.25]);
        $prices = app(\App\Modules\Finance\Accounting\Services\PriceListService::class);

        $p = $prices->priceFor($party, null, 'maize', 'WM1', 'kg');
        $this->assertEquals(4.25, $p['price']);
        $this->assertStringContainsString("this customer's list", $p['basis']);
        $this->assertEquals(4.10, $prices->priceFor($party, null, 'Maize', 'WM2', 'kg')['price'], 'Another grade falls back to the general list.');
        $this->assertEquals(4.10, $prices->priceFor($party, null, 'Maize', 'WM1', 'kg', today()->subWeeks(2)->toDateString())['price'], 'Before the own list starts.');
        $this->assertNull($prices->priceFor(null, null, 'Maize', 'WM1', 't')['price'], 'Another unit is not priced.');
    }

    public function test_a_dispute_holds_the_invoice_and_is_resolved_with_a_credit_note(): void
    {
        $party = $this->customer();
        $order = $this->order($party);
        $dn = app(SalesOrderService::class)->deliver($order, [$order->lines->first()->id => 10]);
        $inv = app(SalesOrderService::class)->invoiceDelivery($dn);
        $disputes = app(\App\Modules\Finance\Accounting\Services\DisputeService::class);

        $d = $disputes->raise($party, ['invoice_id' => $inv->id, 'kind' => 'quality', 'amount' => 115, 'description' => 'One unit damaged'], null);
        $this->assertTrue((bool) $inv->fresh()->disputed);
        $disputes->resolve($d, 'resolved', 'Credit for one unit', 115, null);
        $this->assertFalse((bool) $inv->fresh()->disputed);
        $this->assertSame('resolved', $d->fresh()->status);
        $this->assertSame('credit_note', FinInvoice::findOrFail($d->fresh()->credit_note_id)->doc_type);
    }

    public function test_charge_to_only_accepts_a_registered_choice(): void
    {
        \App\Modules\Finance\Accounting\Support\ChargeTo::register('test_site', fn () => [['value' => 'test_site:7', 'title' => 'Site 7']],
            fn (string $id) => $id === '7' ? ['site' => '7'] : null);
        $this->assertSame(['site' => '7', 'charge_to' => 'test_site:7'], \App\Modules\Finance\Accounting\Support\ChargeTo::dimensions('test_site:7'));
        $this->assertNull(\App\Modules\Finance\Accounting\Support\ChargeTo::dimensions(null));
        $this->expectException(\InvalidArgumentException::class);
        \App\Modules\Finance\Accounting\Support\ChargeTo::dimensions('test_site:8');
    }
}
