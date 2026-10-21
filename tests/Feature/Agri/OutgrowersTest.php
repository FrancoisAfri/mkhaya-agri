<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\GrowerContract;
use App\Modules\Agri\Models\GrowerSettlement;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Services\Outgrowers;
use App\Modules\Agri\Services\PostHarvest;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §16D: a grower contract approved by someone else; inputs advanced within the contract's
 * limit into Grower Input Advances; inspections driving the forecast; graded collections into the
 * store and traced to the grower; and a settlement that bills the gross and recovers the advances,
 * leaving the books balanced and the grower owed the net.
 */
class OutgrowersTest extends AgriTestCase
{
    public function test_a_grower_is_advanced_inputs_collected_from_and_settled_net_with_the_books_in_step(): void
    {
        $this->catalogue();
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $seed = $this->stockItem('SEED', 'bag', 1000);
        $this->stockOnFarm($farm, $seed, 20, 1000);
        $maize = $this->stockItem('MZ-BUY', 'kg', 4);
        $store = $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->first());

        $this->actingAs($this->manager)->post(route('agri.outgrowers.store'), ['farm_id' => $farm->uuid, 'name' => 'Sipho Dlamini', 'code' => 'G-001', 'hectares' => 5])->assertSessionHasNoErrors();
        $g = Grower::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.outgrowers.contracts.store', $g->uuid), ['product' => 'Maize', 'expected_quantity' => 20000, 'unit' => 'kg', 'price' => 4,
            'grade_prices' => ['WM2' => 3.6], 'advance_limit' => 10000])->assertSessionHasNoErrors();
        $c = GrowerContract::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.outgrowers.advances.store', $c->uuid), ['kind' => 'input', 'stock_item_id' => $seed->id, 'quantity' => 4])->assertSessionHasErrors('contract');
        $this->actingAs($this->manager)->post(route('agri.outgrowers.contracts.approve', $c->uuid))->assertSessionHasErrors('contract');
        $this->actingAs($this->admin)->post(route('agri.outgrowers.contracts.approve', $c->uuid))->assertSessionHasNoErrors();

        // Advances: 8 bags of seed (R8 000); 3 more would break the R10 000 limit.
        $this->actingAs($this->manager)->post(route('agri.outgrowers.advances.store', $c->uuid), ['kind' => 'input', 'stock_item_id' => $seed->id, 'quantity' => 8])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.outgrowers.advances.store', $c->uuid), ['kind' => 'input', 'stock_item_id' => $seed->id, 'quantity' => 3])->assertSessionHasErrors('amount');
        $balance = fn (string $code) => (float) DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)
            ->where('l.company_id', $this->company->id)->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as b')->value('b');
        $this->assertEqualsWithDelta(8000.0, $balance('1280'), 0.01, 'The advance is an asset until recovered.');

        // An inspection revises the forecast.
        $this->actingAs($this->manager)->post(route('agri.outgrowers.inspections.store', $c->uuid), ['inspected_on' => today()->toDateString(), 'stage' => 'tasselling',
            'estimated_quantity' => 18000, 'outcome' => 'satisfactory'])->assertSessionHasNoErrors();
        $this->assertEquals(18000, $this->asCompany($this->company, fn () => app(Outgrowers::class)->forecast($farm->id))[0]['expected']);

        // Two collections, graded; a resend of the second changes nothing.
        $this->actingAs($this->manager)->post(route('agri.outgrowers.collections.store', $c->uuid), ['store_id' => $store->uuid, 'stock_item_id' => $maize->id, 'quantity' => 2000, 'rejected' => 100])->assertSessionHasNoErrors();
        $second = ['store_id' => $store->uuid, 'stock_item_id' => $maize->id, 'quantity' => 1000, 'grade' => 'WM2', 'client_id' => 'scale-77'];
        $this->actingAs($this->manager)->post(route('agri.outgrowers.collections.store', $c->uuid), $second)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.outgrowers.collections.store', $c->uuid), $second)->assertSessionHasNoErrors();
        $gross = 1900 * 4 + 1000 * 3.6;   // 7 600 + 3 600 = 11 200
        $this->assertEqualsWithDelta(2900.0, app(\App\Modules\Finance\Accounting\Services\StockLedger::class)->available($maize->fresh(), $store->warehouse_id), 0.001);
        $lot = ProduceLot::withoutGlobalScopes()->where('kind', 'collected')->orderBy('id')->first();
        $trace = $this->asCompany($this->company, fn () => app(PostHarvest::class)->backward($lot));
        $this->assertSame('G-001', $trace['growers'][0]['grower']);
        $this->assertSame('satisfactory', $trace['growers'][0]['last_inspection']['outcome']);

        // Settlement: gross 11 200, less 8 000 advanced = 3 200 to pay; approved by someone else.
        $this->actingAs($this->manager)->post(route('agri.outgrowers.settle', $g->uuid))->assertSessionHasNoErrors();
        $s = GrowerSettlement::withoutGlobalScopes()->sole();
        $this->assertEqualsWithDelta($gross, $s->gross, 0.01);
        $this->assertEqualsWithDelta(8000.0, $s->advances_recovered, 0.01);
        $this->assertEqualsWithDelta(3200.0, $s->net, 0.01);
        $this->actingAs($this->manager)->post(route('agri.outgrowers.settlements.approve', $s->uuid))->assertSessionHasErrors('settlement');
        $this->actingAs($this->admin)->post(route('agri.outgrowers.settlements.approve', $s->uuid))->assertSessionHasNoErrors();

        $bill = FinInvoice::withoutGlobalScopes()->findOrFail($s->fresh()->invoice_id);
        $this->assertEqualsWithDelta($gross, (float) $bill->total, 0.01);
        $this->assertEqualsWithDelta(3200.0, (float) $bill->total - (float) $bill->amount_paid, 0.01, 'The grower is owed the net.');
        $this->assertEqualsWithDelta(0.0, $balance('1280'), 0.01, 'Advances fully recovered.');
        $this->assertEqualsWithDelta(0.0, $balance(config('finance.grni_account', '2150')), 0.01, 'Goods received are fully invoiced.');
        $this->assertEqualsWithDelta(-3200.0, $balance(config('finance.ap_control')), 0.01, 'Accounts payable shows the net owed.');
        $st = $this->asCompany($this->company, fn () => app(Outgrowers::class)->statement($g));
        $this->assertEqualsWithDelta(3200.0, $st['to_pay'], 0.01);
        $this->assertEqualsWithDelta(0.0, $st['advances_outstanding'], 0.01);
        $this->actingAs($this->manager)->get(route('agri.outgrowers.index', ['farm' => $farm->uuid]))->assertOk();
    }
}
