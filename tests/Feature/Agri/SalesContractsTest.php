<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ContractAmendment;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\MarketListing;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Agri\Services\AgriLedger;
use App\Modules\Agri\Services\FarmSales;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPriceList;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Services\DisputeService;
use App\Modules\Finance\Accounting\Services\PriceListService;
use App\Modules\Finance\Accounting\Services\QuoteService;
use App\Modules\Finance\Accounting\Services\SalesOrderService;
use App\Modules\Finance\Accounting\Support\ChargeTo;
use App\Modules\Shared\Party\PartyDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §9 on Accounting's documents: the buyer is an Accounting customer, prices come from
 * Accounting's price lists, the quote and order are Accounting's and charged to the farm, a delivery
 * is invoiced for what the customer accepted with the farm's dimensions, and a dispute ends in a
 * credit note. Farm-specific selling stays on the farm: contracts
 * approved by someone else, priced by fixed price or market formula, changed only by approved
 * amendments, and set against forecast, harvest and stock; market prices with their source; and
 * listings that never pass forecast produce off as stock.
 */
class SalesContractsTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->openBooks();
    }

    private function planted($farm, $field, string $code = 'MZ-1'): CropPlan
    {
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => $code, 'planned_area' => 20, 'area_unit' => 'ha', 'planned_planting_on' => today()->subMonths(5)->toDateString(),
            'planned_harvest_on' => today()->addWeeks(3)->toDateString(), 'expected_yield' => 5000, 'yield_unit' => 'kg'])->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    /** 20 000 kg of maize harvested into the store: one lot. */
    private function stocked($farm, CropPlan $plan): ProduceLot
    {
        $item = $this->stockItem('MZ-BULK', 'kg', 3);
        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), ['harvested_on' => today()->toDateString(), 'quantity' => 20000, 'unit' => 'kg',
            'grade' => 'WM1', 'destination' => 'store', 'stock_item_id' => $item->id, 'unit_value' => 3])->assertSessionHasNoErrors();

        return ProduceLot::withoutGlobalScopes()->where('kind', 'harvest')->latest('id')->firstOrFail();
    }

    /** A customer, as Accounting's customer screen makes one. */
    private function buyer(string $name = 'Noordwes Milling'): FinParty
    {
        return $this->asCompany($this->company, fn () => app(PartyDirectory::class)->create(['company_id' => $this->company->id, 'kind' => 'customer', 'name' => $name,
            'credit_terms_days' => 30, 'channel' => 'contract', 'delivery_points' => [['name' => 'Mill', 'address' => 'Lichtenburg mill, gate 2']]], 'finance'));
    }

    /** Pick, count, dispatch and deliver an order in full, with whatever was rejected: the warehouse's load and shipment. */
    private function fulfil(FinSalesOrder $order, ProduceLot $lot, float $qty, float $rejected = 0): Shipment
    {
        $line = $order->lines()->first();
        $this->actingAs($this->manager)->post(route('agri.packhouse.orders.pick', $order->uuid), ['items' => [['order_line_id' => $line->id, 'lot_id' => $lot->id, 'quantity' => $qty]]])->assertSessionHasNoErrors();
        $load = FulfilmentOrder::query()->latest('id')->firstOrFail();
        $item = $load->packages()->firstOrFail()->contents()->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.packhouse.dispatches.verify', $load->uuid), ['counts' => [$item->id => $qty]])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.deliver', $load->uuid), ['results' => [$item->id => ['accepted' => $qty - $rejected, 'rejected' => $rejected,
            'rejection_reason' => $rejected ? 'Moisture over 14%' : null, 'rejected_to' => $rejected ? 'buyer_kept' : null]]])->assertSessionHasNoErrors();

        return $load->shipments()->firstOrFail();
    }

    public function test_9_1_a_farm_sale_runs_on_accounting_documents_charged_to_the_farm_and_a_dispute_ends_in_a_credit_note(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'L1', 20));
        $lot = $this->stocked($farm, $plan);
        $buyer = $this->buyer();

        // Accounting's price lists: the customer's own list beats the general one, by product, grade and unit.
        $this->asCompany($this->company, function () use ($buyer, $lot) {
            FinPriceList::create(['company_id' => $this->company->id, 'name' => 'General 2026', 'valid_from' => today()->subMonth()])
                ->items()->create(['description' => $lot->product, 'grade' => 'WM1', 'unit' => 'kg', 'price' => 4.10]);
            FinPriceList::create(['company_id' => $this->company->id, 'name' => 'Noordwes 2026', 'party_id' => $buyer->id, 'valid_from' => today()->subMonth()])
                ->items()->create(['description' => $lot->product, 'grade' => 'WM1', 'unit' => 'kg', 'price' => 4.25]);
        });
        $price = $this->asCompany($this->company, fn () => app(PriceListService::class)->priceFor($buyer, null, $lot->product, 'WM1', 'kg'));
        $this->assertEquals(4.25, $price['price']);
        $this->assertStringContainsString("this customer's list", $price['basis']);
        $this->assertNull($this->asCompany($this->company, fn () => app(PriceListService::class)->priceFor($buyer, null, $lot->product, 'WM1', 'kg', today()->subMonths(2)->toDateString()))['price'],
            'A list is not used before it starts.');

        // Raised in Accounting and charged to the farm: the person picks the farm, Accounting resolves its dimensions.
        $this->actingAs($this->manager);
        $options = $this->asCompany($this->company, fn () => ChargeTo::options());
        $this->assertContains("agri_farm:{$farm->uuid}", array_column($options, 'value'));
        $dims = $this->asCompany($this->company, fn () => ChargeTo::dimensions("agri_farm:{$farm->uuid}"));
        $this->assertSame((string) $farm->id, $dims['agri_farm']);
        $account = $this->asCompany($this->company, fn () => FarmSales::salesAccountId());
        $quote = $this->asCompany($this->company, fn () => app(QuoteService::class)->createQuote(['party_id' => $buyer->id, 'dimensions' => $dims,
            'lines' => [['account_id' => $account, 'description' => $lot->product, 'grade' => 'WM1', 'unit' => 'kg', 'quantity' => 8000, 'unit_price' => $price['price'], 'price_basis' => $price['basis']]]]));
        $order = $this->asCompany($this->company, fn () => app(SalesOrderService::class)->createFromQuote($quote->fresh('lines')));
        $order->update(['deliver_on' => today()->addDays(3), 'delivery_address' => 'Lichtenburg mill, gate 2']);
        $this->assertSame('WM1', $order->lines->first()->grade);
        $this->assertSame((string) $farm->id, $order->dimensions['agri_farm']);

        // The farm picks, checks and sends it; the customer accepts 7 800 kg. Accounting's delivery note
        // records it and the accepted quantity is invoiced, the income carrying the farm.
        $d = $this->fulfil($order->fresh('lines'), $lot, 8000, 200);
        $inv = FinInvoice::withoutGlobalScopes()->findOrFail(DB::table('fin_delivery_notes')->where('id', $d->delivery_note_id)->value('invoice_id'));
        $this->assertEqualsWithDelta(7800 * 4.25, (float) $inv->total, 0.01);
        $this->assertSame($buyer->id, (int) $inv->party_id);
        $income = DB::table('fin_journal_lines')->where('journal_id', $inv->journal_id)->where('credit', '>', 0)->first();
        $this->assertSame((string) $farm->id, json_decode($income->dimensions, true)['agri_farm'] ?? null, 'The invoice lands in the farm\'s profitability.');
        $this->assertSame(1, DB::table('fin_stock_movements')->where('type', 'dispatch')->count(), 'Stock left once, on the shipment, not again on the invoice.');
        $line = $order->fresh('lines')->lines->first();
        $this->assertEquals([8000.0, 7800.0, 200.0, 7800.0], [(float) $line->qty_delivered, (float) $line->qty_accepted, (float) $line->qty_rejected, (float) $line->qty_invoiced]);
        $this->assertSame('invoiced', $order->fresh()->status);
        $this->assertNotNull(DB::table('fin_delivery_notes')->where('id', $d->delivery_note_id)->value('invoice_id'));

        $st = $this->asCompany($this->company, fn () => app(FarmSales::class)->statement($buyer));
        $this->assertEqualsWithDelta(33150.0, $st['outstanding'], 0.01);

        // A price dispute is Accounting's: the invoice is held; resolved with a credit note that also carries the farm.
        $dispute = $this->asCompany($this->company, fn () => app(DisputeService::class)->raise($buyer, ['invoice_id' => $inv->id, 'subject_type' => 'wms_shipment',
            'subject_id' => $d->id, 'kind' => 'price', 'amount' => 780, 'description' => 'Buyer says the list price was 4.15'], $this->manager->id));
        $this->assertTrue((bool) $inv->fresh()->disputed);
        $this->asCompany($this->company, fn () => app(DisputeService::class)->resolve($dispute, 'resolved', 'Agreed 10c/kg discount', 780, $this->admin->id));
        $this->assertFalse((bool) $inv->fresh()->disputed);
        $cn = FinInvoice::withoutGlobalScopes()->findOrFail($dispute->fresh()->credit_note_id);
        $debit = DB::table('fin_journal_lines')->where('journal_id', $cn->journal_id)->where('debit', '>', 0)->first();
        $this->assertSame((string) $farm->id, json_decode($debit->dimensions, true)['agri_farm'] ?? null);
        $this->assertEqualsWithDelta(32370.0, $this->asCompany($this->company, fn () => app(FarmSales::class)->statement($buyer))['outstanding'], 0.01);

        // The farm's Sales page keeps contracts and the market, and links to Accounting for the rest.
        $page = $this->actingAs($this->manager)->get(route('agri.sales', ['tab' => 'contracts', 'farm' => $farm->uuid]))->assertOk()->viewData('page')['props'];
        $this->assertStringEndsWith('/finance/accounting/quotes', $page['accounting']['quotes']);
        $this->assertContains($this->actingAs($this->manager)->get('/agri/sales/quotes')->status(), [403, 404], 'The farm has no quotes page of its own.');
        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'dispatch', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_9_2_a_contract_is_approved_by_someone_else_priced_by_formula_amended_with_history_and_set_against_forecast_harvest_and_stock(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'L1', 20));
        $planB = $this->planted($farm, $this->field($farm, 'L2', 20), 'MZ-2');
        $lot = $this->stocked($farm, $plan);
        $buyer = $this->buyer();
        $win = [['from' => today()->toDateString(), 'to' => today()->addMonth()->toDateString(), 'quantity' => 60000, 'location' => 'Lichtenburg mill']];

        // Windows must add up; a formula needs its market.
        $base = ['party_id' => $buyer->id, 'crop_type_id' => $plan->crop_type_id, 'product' => $lot->product, 'quantity' => 60000, 'unit' => 'kg', 'tolerance_pct' => 5,
            'accepted_grades' => ['WM1', 'WM2'], 'pricing' => 'formula', 'transport' => 'farm', 'rejection_rules' => 'Moisture over 14% rejected', 'advance_amount' => 50000,
            'input_financing' => [['item' => 'Seed', 'value' => 30000, 'recover_from' => 'first delivery']]];
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.store'), $base + ['windows' => [['from' => today()->toDateString(), 'to' => today()->addMonth()->toDateString(), 'quantity' => 50000]],
            'formula' => ['market' => 'SAFEX']])->assertSessionHasErrors('windows');
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.store'), $base + ['windows' => $win])->assertSessionHasErrors('formula');
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.store'), $base + ['windows' => $win, 'formula' => ['market' => 'SAFEX', 'product' => $lot->product, 'unit' => 'kg',
            'lookback_days' => 7, 'adjust_pct' => -5, 'premium' => 0.1, 'floor' => 3.5, 'grade_premiums' => ['WM1' => 0.05]]])->assertSessionHasNoErrors();
        $c = SalesContract::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.status', $c->uuid), ['status' => 'active'])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->post(route('agri.sales.contracts.status', $c->uuid), ['status' => 'active'])->assertSessionHasNoErrors();

        // The formula follows the market, and says which price it used.
        $sales = app(FarmSales::class);
        $this->assertNull($this->asCompany($this->company, fn () => $sales->contractPrice($c->fresh(), 'WM1'))['price']);
        $this->actingAs($this->manager)->post(route('agri.sales.prices.store'), ['product' => $lot->product, 'market' => 'SAFEX', 'price_date' => today()->addDay()->toDateString(), 'price' => 4,
            'currency' => 'ZAR', 'unit' => 'kg', 'kind' => 'actual', 'source' => 'JSE'])->assertStatus(422);
        $this->actingAs($this->manager)->post(route('agri.sales.prices.store'), ['product' => $lot->product, 'market' => 'SAFEX', 'price_date' => today()->subDay()->toDateString(), 'price' => 4,
            'currency' => 'ZAR', 'unit' => 'kg', 'kind' => 'actual', 'source' => 'JSE commodity derivatives'])->assertSessionHasNoErrors();
        $p = $this->asCompany($this->company, fn () => $sales->contractPrice($c->fresh(), 'WM1'));
        $this->assertEqualsWithDelta(4 * 0.95 + 0.1 + 0.05, $p['price'], 0.0001);
        $this->assertStringContainsString('SAFEX', $p['basis']);
        $this->assertStringContainsString('JSE', $p['basis']);

        // Orders on it: inside a window, accepted grades only.
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.orders', $c->uuid), ['farm_id' => $farm->uuid, 'deliver_on' => today()->addMonths(2)->toDateString(),
            'lines' => [['grade' => 'WM1', 'quantity' => 10000]]])->assertSessionHasErrors('deliver_on');
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.orders', $c->uuid), ['farm_id' => $farm->uuid, 'deliver_on' => today()->addDays(2)->toDateString(),
            'lines' => [['grade' => 'WM3', 'quantity' => 10000]]])->assertSessionHasErrors('lines');
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.orders', $c->uuid), ['farm_id' => $farm->uuid, 'deliver_on' => today()->addDays(2)->toDateString(),
            'lines' => [['grade' => 'WM1', 'quantity' => 15000]]])->assertSessionHasNoErrors();
        $order = FinSalesOrder::withoutGlobalScopes()->where('source_type', 'agri_contract')->sole();
        $this->assertEqualsWithDelta(3.95, (float) $order->lines()->first()->unit_price, 0.0001);
        $this->assertStringContainsString('SAFEX', $order->lines()->first()->price_basis);
        $this->fulfil($order, $lot, 15000);
        $pos = $this->asCompany($this->company, fn () => $sales->position($c->fresh()));
        $this->assertEquals(15000, $pos['delivered']);
        $this->assertEquals(45000, $pos['remaining']);

        // An amendment applies only once someone else approves it, and keeps the history.
        $this->actingAs($this->manager)->post(route('agri.sales.contracts.amend', $c->uuid), ['quantity' => 70000, 'windows' => [$win[0] + ['quantity' => 70000]], 'reason' => 'Second block added'])->assertSessionHasNoErrors();
        $a = ContractAmendment::withoutGlobalScopes()->sole();
        $this->assertEquals([60000, 70000], $a->changes['quantity']);
        $this->assertEquals(60000, $c->fresh()->quantity);
        $this->actingAs($this->manager)->post(route('agri.sales.amendments.decide', $a->uuid), ['approve' => true])->assertSessionHasErrors('amendment');
        $this->actingAs($this->admin)->post(route('agri.sales.amendments.decide', $a->uuid), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertEquals(70000, $c->fresh()->quantity);
        $this->assertSame(2, (int) $c->fresh()->version);

        // Committed against supply: 55 000 still owed; forecast 2 × 100 000 less the 20 000 harvested, plus 5 000 left on hand.
        $cp = $this->asCompany($this->company, fn () => $sales->cropPosition($this->company->id, $plan->crop_type_id));
        $this->assertEquals(55000, $cp['committed']);
        $this->assertEquals(20000, $cp['harvested']);
        $this->assertEquals(180000, $cp['projected_harvest']);
        $this->assertEquals(5000, $cp['on_hand']);
        $this->assertEquals(185000 - 55000, $cp['gap']);

        $this->actingAs($this->manager)->get(route('agri.sales', ['tab' => 'contracts', 'farm' => $farm->uuid]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.sales', ['tab' => 'position', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_9_3_and_9_4_listings_keep_stock_and_forecast_apart_and_prices_carry_their_source_with_margins_labelled_as_estimates(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'L1', 20));
        $planB = $this->planted($farm, $this->field($farm, 'L2', 20), 'MZ-2');
        $lot = $this->stocked($farm, $plan);

        // "In stock" must be on hand; a forecast listing comes from a live crop and says so.
        $this->actingAs($this->manager)->post(route('agri.sales.listings.store'), ['farm_id' => $farm->uuid, 'product' => $lot->product, 'quantity' => 30000, 'unit' => 'kg',
            'availability' => 'in_stock', 'lot_ids' => [$lot->id]])->assertSessionHasErrors('quantity');
        $this->actingAs($this->manager)->post(route('agri.sales.listings.store'), ['farm_id' => $farm->uuid, 'product' => $lot->product, 'grade' => 'WM1', 'quantity' => 15000, 'unit' => 'kg',
            'availability' => 'in_stock', 'lot_ids' => [$lot->id], 'asking_price' => 4.2])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.sales.listings.store'), ['farm_id' => $farm->uuid, 'product' => $lot->product, 'grade' => 'WM1', 'quantity' => 80000, 'unit' => 'kg',
            'availability' => 'forecast', 'crop_plan_id' => $planB->id])->assertSessionHasNoErrors();
        $forecast = MarketListing::withoutGlobalScopes()->where('availability', 'forecast')->sole();
        $this->assertNull($forecast->lot_ids);
        $this->assertSame($planB->planned_harvest_on->toDateString(), $forecast->available_from->toDateString());

        // A buyer request is matched to both, the forecast only if it is ready in time.
        $this->actingAs($this->manager)->post(route('agri.sales.requests.store'), ['buyer_name' => 'Kroonstad feedlot', 'product' => $lot->product, 'grade' => 'WM1', 'quantity' => 40000,
            'unit' => 'kg', 'needed_by' => today()->addWeek()->toDateString()])->assertSessionHasNoErrors();
        $m = $this->asCompany($this->company, fn () => app(FarmSales::class)->matches($this->company->id));
        $this->assertSame(['in_stock'], array_column($m[0]['listings'], 'availability'), 'The forecast crop is not ready by the date needed.');

        // Prices: a source on every one; forecasts kept apart and labelled.
        foreach ([[60, 3.6], [30, 3.8], [3, 4.0]] as [$daysAgo, $price]) {
            $this->actingAs($this->manager)->post(route('agri.sales.prices.store'), ['product' => $lot->product, 'market' => 'SAFEX', 'region' => 'National', 'price_date' => today()->subDays($daysAgo)->toDateString(),
                'price' => $price, 'currency' => 'ZAR', 'unit' => 'kg', 'kind' => 'actual', 'source' => 'JSE'])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->manager)->post(route('agri.sales.prices.store'), ['product' => $lot->product, 'market' => 'Local co-op', 'region' => 'North West', 'price_date' => today()->subDays(2)->toDateString(),
            'price' => 3.7, 'currency' => 'ZAR', 'unit' => 'kg', 'kind' => 'actual', 'source' => 'Co-op notice board'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.sales.prices.store'), ['product' => $lot->product, 'market' => 'SAFEX', 'price_date' => today()->addMonths(2)->toDateString(),
            'price' => 4.4, 'currency' => 'ZAR', 'unit' => 'kg', 'kind' => 'forecast', 'source' => 'Bank outlook'])->assertSessionHasNoErrors();
        $sales = app(FarmSales::class);
        $t = $this->asCompany($this->company, fn () => $sales->trends($lot->product));
        $safex = collect($t['actual'])->firstWhere('market', 'SAFEX');
        $this->assertSame(['JSE'], $safex['sources']);
        $this->assertEquals(4.0, $safex['latest']['price']);
        $this->assertStringContainsString('not a guaranteed', strtolower($t['forecast'][0]['label']));
        $mg = $this->asCompany($this->company, fn () => $sales->margins($lot->product, 'kg', 0.35, 0.1));
        $this->assertSame(['SAFEX', 'Local co-op'], array_column($mg['rows'], 'market'));
        $this->assertEqualsWithDelta(3.55, $mg['rows'][0]['net'], 0.0001);
        $this->assertStringContainsString('Estimate', $mg['label']);

        $this->actingAs($this->manager)->get(route('agri.sales', ['tab' => 'market', 'farm' => $farm->uuid, 'product' => $lot->product, 'transport' => 0.35]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.sales', ['tab' => 'marketplace', 'farm' => $farm->uuid]))->assertOk();
    }
}
