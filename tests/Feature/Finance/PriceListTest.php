<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPriceList;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PriceListTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }
    }

    public function test_customer_price_list_overrides_the_item_default_in_the_quote_payload(): void
    {
        $item = FinItem::create(['code' => 'IT' . uniqid(), 'name' => 'Widget', 'unit' => 'each', 'type' => 'product',
            'sales_price' => 100, 'tax_rate' => 15, 'sales_account_id' => FinAccount::where('code', '4100')->value('id')]);

        $list = FinPriceList::create(['name' => 'Wholesale']);
        $list->items()->create(['item_id' => $item->id, 'price' => 80]); // discounted

        $party = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'C' . uniqid(), 'name' => 'Reseller', 'price_list_id' => $list->id]);

        $req = \Illuminate\Http\Request::create('/x', 'GET');
        $req->headers->set('X-Inertia', 'true');
        app()->instance('request', $req);
        $props = app(\App\Modules\Finance\Accounting\Http\Controllers\FinanceQuoteController::class)
            ->index()->toResponse($req)->getData()->props;

        // The customer carries the price list, and the lookup has the override.
        $customer = collect($props->customers)->firstWhere('id', $party->id);
        $this->assertEquals($list->id, $customer->price_list_id);
        $this->assertEquals(80.0, $props->priceLists->{$list->id}->{$item->id});
    }
}
