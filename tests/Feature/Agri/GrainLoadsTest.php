<?php

namespace Tests\Feature\Agri;

use App\Core\Role\Models\Role;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\GrainSettlement;
use App\Modules\Agri\Models\GrainTicket;
use App\Modules\Agri\Models\GrainTicketPhoto;
use App\Modules\Agri\Models\GrainWeighing;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Agri\Services\FarmInventory;
use App\Modules\Agri\Services\FarmSales;
use App\Modules\Agri\Services\GrainGrading;
use App\Modules\Agri\Services\GrainLoads;
use App\Modules\Agri\Services\GrainSettlements;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\HR\Hr\Attendance\Services\ClockDevices;
use App\Modules\Shared\Party\PartyDirectory;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Grain load by load: the weighbridge pairs a truck's two weighings into its ticket (from the device
 * or by hand) and flags a tare that is off; the grading standard's deductions give the settled mass;
 * a load into the silo is the harvest path's stock, by grade; a load out of the silo takes its lots;
 * the reconciliation finds lost and duplicate loads; the buyer's statement is matched line by line,
 * differences named; the contract's balance follows the loads; and the settlement posts through
 * AgriLedger::sale like every farm sale.
 */
class GrainLoadsTest extends AgriTestCase
{
    private \App\Modules\Agri\Models\Farm $farmA;

    private CropPlan $plan;

    private FarmStore $silo;

    private int $item;

    private FinParty $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->openBooks();
        $this->farmA = $this->farm('NORTH');
        $this->member($this->manager, $this->farmA);
        $this->plan = $this->planted('MZ-1', 'L1');
        $this->silo = $this->asCompany($this->company, fn () => app(FarmInventory::class)->addStore($this->farmA, ['kind' => 'silo', 'name' => 'Silo 1', 'capacity' => 500, 'capacity_unit' => 't']));
        $this->item = $this->stockItem('MZ-W', 't', 3000)->id;
        $this->buyer = $this->asCompany($this->company, fn () => app(PartyDirectory::class)->create(['company_id' => $this->company->id, 'kind' => 'customer', 'name' => 'Afgri Bothaville'], 'finance'));
    }

    private function planted(string $code, string $field): CropPlan
    {
        $f = $this->field($this->farmA, $field, 50);
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), ['farm_id' => $this->farmA->uuid, 'field_id' => $f->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId('MAIZE-W'), 'code' => $code, 'planned_area' => 50, 'area_unit' => 'ha', 'planned_planting_on' => today()->subMonths(5)->toDateString(),
            'planned_harvest_on' => today()->addWeeks(2)->toDateString(), 'expected_yield' => 8, 'yield_unit' => 't'])->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    /** A whole ticket typed from the slip. */
    private function capture(array $o): GrainTicket
    {
        $this->actingAs($this->manager)->post(route('agri.grain.capture'), $o + ['farm_id' => $this->farmA->uuid, 'movement' => 'field_to_store', 'crop_plan_id' => $this->plan->uuid,
            'to_store_id' => $this->silo->uuid, 'stock_item_id' => $this->item, 'vehicle_reg' => 'CA 111-222', 'gross_kg' => 44000, 'tare_kg' => 14000, 'moisture_pct' => 12,
            'foreign_matter_pct' => 0.2, 'unit_value' => 3000])->assertSessionHasNoErrors();

        return GrainTicket::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function contract(array $o = []): SalesContract
    {
        $c = $this->asCompany($this->company, fn () => app(FarmSales::class)->contract($this->company->id, $o + ['farm_id' => $this->farmA->id, 'party_id' => $this->buyer->id,
            'crop_type_id' => $this->cropTypeId('MAIZE-W'), 'product' => 'White maize', 'quantity' => 100, 'unit' => 't', 'tolerance_pct' => 5,
            'windows' => [['from' => today()->subMonth()->toDateString(), 'to' => today()->addMonth()->toDateString(), 'quantity' => 100]], 'accepted_grades' => ['WM1', 'WM2', 'WM3'],
            'pricing' => 'fixed', 'price' => 4000, 'formula' => ['grade_premiums' => ['WM2' => -150]], 'currency' => 'ZAR'], $this->manager));
        $this->asCompany($this->company, fn () => app(FarmSales::class)->setStatus($c, 'active', $this->admin));

        return $c->fresh();
    }

    public function test_the_weighbridge_pairs_two_weighings_into_the_ticket_logged_in_the_field_and_flags_an_odd_tare(): void
    {
        [$bridge, $secret] = $this->asCompany($this->company, fn () => app(ClockDevices::class)->register($this->farmA, 'Main weighbridge', 'weighbridge', $this->manager));
        [$sensor, $sensorSecret] = $this->asCompany($this->company, fn () => app(ClockDevices::class)->register($this->farmA, 'Gateway', 'sensor_gateway', $this->manager));
        $url = route('api.agri.devices.weighings', $bridge->uuid);

        // Only the weighbridge, with its own secret.
        $this->withToken('wrong')->postJson($url, ['weighings' => [['vehicle_reg' => 'X', 'mass_kg' => 1]]])->assertUnauthorized();
        $this->withToken($sensorSecret)->postJson(route('api.agri.devices.weighings', $sensor->uuid), ['weighings' => [['vehicle_reg' => 'X', 'mass_kg' => 1]]])->assertUnauthorized();

        // The load leaves the field; the bridge finds it by the truck, whatever way the reg is typed.
        $this->actingAs($this->manager)->post(route('agri.grain.dispatch'), ['farm_id' => $this->farmA->uuid, 'movement' => 'field_to_store', 'crop_plan_id' => $this->plan->uuid,
            'to_store_id' => $this->silo->uuid, 'stock_item_id' => $this->item, 'vehicle_reg' => 'CA 123-456', 'driver' => 'Sipho', 'load_ref' => 'L-7', 'unit_value' => 3000])->assertSessionHasNoErrors();
        $t = GrainTicket::withoutGlobalScopes()->sole();
        $this->assertSame('dispatched', $t->status);
        $this->assertSame('CA123456', $t->vehicle_reg);

        $r = $this->withToken($secret)->postJson($url, ['weighings' => [['id' => 'WB-1', 'vehicle_reg' => 'ca123456', 'mass_kg' => 44000, 'weighed_at' => now()->subMinutes(30)->toIso8601String()]]])
            ->assertOk()->json('results.0');
        $this->assertTrue($r['ok']);
        $this->assertSame($t->number, $r['ticket']);
        $this->assertSame('weighing', $t->fresh()->status);
        // The same weighing sent again is answered from the first time, not weighed twice.
        $again = $this->withToken($secret)->postJson($url, ['weighings' => [['id' => 'WB-1', 'vehicle_reg' => 'ca123456', 'mass_kg' => 44000]]])->assertOk()->json('results.0');
        $this->assertTrue($again['replayed']);
        $this->assertSame(1, GrainWeighing::withoutGlobalScopes()->count());

        // The empty truck: paired, gross the larger. No moisture yet, so it waits for the grader.
        $this->withToken($secret)->postJson($url, ['weighings' => [['id' => 'WB-2', 'vehicle_reg' => 'CA 123 456', 'mass_kg' => 14000, 'weighed_at' => now()->toIso8601String()]]])->assertOk();
        $t = $t->fresh();
        $this->assertSame('weighed', $t->status);
        $this->assertEquals(44000, $t->gross_kg);
        $this->assertEquals(14000, $t->tare_kg);
        $this->assertEquals(30000, $t->net_kg);
        $this->assertSame('device', $t->weigh_source);

        // The grader's reading completes it: graded, deducted, into the silo through the harvest.
        $this->actingAs($this->manager)->post(route('agri.grain.tickets.assess', $t->uuid), ['moisture_pct' => 15, 'foreign_matter_pct' => 0.4,
            'photos' => [UploadedFile::fake()->image('sample.jpg')]])->assertSessionHasNoErrors();
        $t = $t->fresh();
        $this->assertSame('complete', $t->status);
        $this->assertSame('WM2', $t->grade, 'Foreign matter 0.4% is over WM1\'s 0.3% and within WM2\'s 0.5%.');
        $this->assertEquals(28993, $t->settled_kg);
        $h = HarvestRecord::withoutGlobalScopes()->findOrFail($t->harvest_record_id);
        $this->assertSame('store', $h->destination);
        $this->assertSame('weighbridge', $h->measuring_method);
        $this->assertEqualsWithDelta(28.993, (float) $h->quantity, 0.0005);
        $this->assertSame(1, GrainTicketPhoto::withoutGlobalScopes()->where('ticket_id', $t->id)->count());
        $photo = GrainTicketPhoto::withoutGlobalScopes()->sole();
        $this->assertNotEmpty(DB::table('agri_grain_ticket_photos')->where('id', $photo->id)->value('data'), 'Photos are kept in the database.');
        $this->actingAs($this->manager)->get(route('agri.grain.photos.show', $photo->uuid))->assertOk();

        // Two more loads with the truck's usual 14 t tare; then one that comes in 10% heavier empty.
        $this->capture(['vehicle_reg' => 'CA 123-456', 'gross_kg' => 43800, 'weighed_in_at' => now()->subHours(3)->toDateTimeString(), 'weighed_out_at' => now()->subHours(2)->toDateTimeString()]);
        $this->withToken($secret)->postJson($url, ['weighings' => [['id' => 'WB-3', 'vehicle_reg' => 'CA123456', 'mass_kg' => 45000, 'kind' => 'gross'],
            ['id' => 'WB-4', 'vehicle_reg' => 'CA123456', 'mass_kg' => 15400, 'kind' => 'tare']]])->assertOk();
        $odd = GrainTicket::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('weighed', $odd->status, 'Opened by the bridge itself: nobody has said where it went.');
        $this->assertContains('tare_anomaly', array_column($odd->flags, 'code'));
        $this->assertStringContainsString('10%', collect($odd->flags)->firstWhere('code', 'tare_anomaly')['message']);

        // A weighing typed in at the bridge pairs the same way; and a third weighing on a ticket is refused.
        $this->actingAs($this->manager)->post(route('agri.grain.weighings.store'), ['farm_id' => $this->farmA->uuid, 'vehicle_reg' => 'NW 77 ZZ', 'mass_kg' => 40000])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.grain.weighings.store'), ['farm_id' => $this->farmA->uuid, 'vehicle_reg' => 'NW 77 ZZ', 'mass_kg' => 12500])->assertSessionHasNoErrors();
        $manual = GrainTicket::withoutGlobalScopes()->where('vehicle_reg', 'NW77ZZ')->sole();
        $this->assertEquals(27500, $manual->net_kg);
        $this->assertSame('manual', $manual->weigh_source);
        $this->actingAs($this->manager)->post(route('agri.grain.weighings.store'), ['farm_id' => $this->farmA->uuid, 'ticket' => $manual->number, 'mass_kg' => 12000])
            ->assertSessionHasErrors('ticket');

        $this->actingAs($this->manager)->get(route('agri.grain', 'weighbridge'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Grain/Index')->where('data.devices.0.name', 'Main weighbridge')->has('data.flagged', 1));
    }

    public function test_deductions_follow_the_grading_standard_and_the_grade_follows_the_readings(): void
    {
        $g = app(GrainGrading::class);
        $this->asCompany($this->company, fn () => $g->ensureDefaults($this->company->id));
        $maize = $this->asCompany($this->company, fn () => $g->standardFor($this->cropTypeId('MAIZE-W')));
        $this->assertEquals(12.5, $maize->grain_rules['base_moisture_pct']);

        // 30 t net at 15% moisture and 2.5% foreign matter: shrink to 12.5%, 0.5% handling, screenings over the 0.5% allowance.
        $d = $g->deductions(30000, 15, 2.5, $maize->grain_rules);
        $this->assertEquals(857, $d['moisture_kg']);      // 30 000 × 2.5 ÷ 87.5 = 857.1
        $this->assertEquals(150, $d['handling_kg']);
        $this->assertEquals(600, $d['screenings_kg']);    // 30 000 × 2% = 600
        $this->assertEquals(28393, $d['settled_kg']);
        // Dry and clean: nothing off.
        $this->assertEquals(30000, $g->deductions(30000, 12.0, 0.3, $maize->grain_rules)['settled_kg']);

        $this->assertSame('WM1', $g->suggestGrade($maize, ['foreign_matter_pct' => 0.2, 'defects_pct' => 5]));
        $this->assertSame('WM3', $g->suggestGrade($maize, ['foreign_matter_pct' => 0.6]));
        $this->assertSame('COW', $g->suggestGrade($maize, ['foreign_matter_pct' => 1.2]));
        $wheat = $this->asCompany($this->company, fn () => $g->standardFor($this->cropTypeId('WHEAT')));
        $this->assertSame('B2', $g->suggestGrade($wheat, ['protein_pct' => 11.5, 'hectolitre_kg' => 77]));
        $this->assertSame('UT', $g->suggestGrade($wheat, ['protein_pct' => 8]));

        // The same arithmetic on a captured ticket, its basis written out.
        $t = $this->capture(['moisture_pct' => 15, 'foreign_matter_pct' => 2.5, 'grade' => 'WM3']);
        $this->assertEquals(857, $t->moisture_deduction_kg);
        $this->assertEquals(150, $t->handling_deduction_kg);
        $this->assertEquals(600, $t->screenings_deduction_kg);
        $this->assertEquals(28393, $t->settled_kg);
        $this->assertSame('WM3', $t->grade);
        $this->assertSame('COW', $t->suggested_grade);
        $this->assertStringContainsString('12.5% base', $t->deduction_basis[0]);
    }

    public function test_the_silo_holds_its_grain_by_grade_through_the_harvest_path_and_a_load_out_takes_its_lots(): void
    {
        $a = $this->capture(['foreign_matter_pct' => 0.2]);                                         // WM1, 30 t
        $b = $this->capture(['vehicle_reg' => 'CA 333', 'gross_kg' => 40000, 'tare_kg' => 15000, 'foreign_matter_pct' => 0.45, 'moisture_pct' => 13]);   // WM2, 25 t less shrink
        $this->assertSame(['WM1', 'WM2'], [$a->grade, $b->grade]);
        $loads = app(GrainLoads::class);
        $bin = $this->asCompany($this->company, fn () => $loads->bin($this->silo->fresh()));
        $this->assertSame(2, $bin['in']['loads']);
        $this->assertEqualsWithDelta(55.0, $bin['in']['net_t'], 0.001);
        $this->assertEqualsWithDelta(30.0, $bin['on_hand']['by_grade']['WM1'], 0.001);
        $this->assertEqualsWithDelta((float) $b->settled_kg / 1000, $bin['on_hand']['by_grade']['WM2'], 0.001);
        $this->assertEqualsWithDelta($bin['in']['moisture_t'] + $bin['in']['handling_t'], $bin['shrink_and_handling_t'], 0.0001);
        $this->assertEqualsWithDelta(0, $bin['unexplained_t'], 0.001);
        // It is the shared inventory's stock, not a second count.
        $this->assertEqualsWithDelta($bin['on_hand']['t'], (float) DB::table('fin_stock_positions')->where('warehouse_id', $this->silo->warehouse_id)->sum('qty'), 0.001);

        // Out of the silo to the buyer: 12 t of WM1, its lot first.
        $this->actingAs($this->manager)->post(route('agri.grain.capture'), ['farm_id' => $this->farmA->uuid, 'movement' => 'store_to_buyer', 'from_store_id' => $this->silo->uuid,
            'stock_item_id' => $this->item, 'party_id' => $this->buyer->id, 'vehicle_reg' => 'CA 444', 'gross_kg' => 26000, 'tare_kg' => 14000, 'moisture_pct' => 12, 'grade' => 'WM1'])->assertSessionHasNoErrors();
        $out = GrainTicket::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('complete', $out->status);
        $this->assertSame($a->number, $out->stock_out[0]['lot']);
        $bin = $this->asCompany($this->company, fn () => $loads->bin($this->silo->fresh()));
        $this->assertSame(1, $bin['out']['loads']);
        $this->assertEqualsWithDelta(18.0, $bin['on_hand']['by_grade']['WM1'], 0.001);
        $this->assertEqualsWithDelta(0, $bin['unexplained_t'], 0.001);

        // More than the silo holds is refused.
        $this->actingAs($this->manager)->post(route('agri.grain.capture'), ['farm_id' => $this->farmA->uuid, 'movement' => 'store_to_buyer', 'from_store_id' => $this->silo->uuid,
            'stock_item_id' => $this->item, 'party_id' => $this->buyer->id, 'vehicle_reg' => 'CA 555', 'gross_kg' => 80000, 'tare_kg' => 10000, 'moisture_pct' => 12])->assertSessionHasErrors('net_kg');

        $this->actingAs($this->manager)->get(route('agri.grain', 'bins'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Grain/Index')->has('data.bins', 2)->where('data.bins.1.store.name', 'Silo 1'));
    }

    public function test_the_reconciliation_finds_lost_loads_missing_receipts_and_duplicates(): void
    {
        // Left the field eight hours ago and never weighed in.
        $this->actingAs($this->manager)->post(route('agri.grain.dispatch'), ['farm_id' => $this->farmA->uuid, 'movement' => 'field_to_store', 'crop_plan_id' => $this->plan->uuid,
            'to_store_id' => $this->silo->uuid, 'stock_item_id' => $this->item, 'vehicle_reg' => 'LOST 1', 'load_ref' => 'L-12', 'dispatched_at' => now()->subHours(8)->toDateTimeString()])->assertSessionHasNoErrors();
        // Two deliveries to the buyer; the statement for the day carries only one.
        $c = $this->contract();
        $deliver = fn (string $reg, float $gross, ?string $ref = null) => $this->actingAs($this->manager)->post(route('agri.grain.capture'), ['farm_id' => $this->farmA->uuid,
            'movement' => 'field_to_buyer', 'crop_plan_id' => $this->plan->uuid, 'party_id' => $this->buyer->id, 'contract_id' => $c->uuid, 'vehicle_reg' => $reg, 'gross_kg' => $gross,
            'tare_kg' => 14000, 'moisture_pct' => 12, 'foreign_matter_pct' => 0.2, 'buyer_ticket_ref' => $ref])->assertSessionHasNoErrors();
        $deliver('BUY 1', 44000, 'AF-100');
        $deliver('BUY 2', 43000);
        $deliver('BUY 3', 42000, 'AF-100');                                                      // the buyer's receipt written on a second ticket
        $one = GrainTicket::withoutGlobalScopes()->where('vehicle_reg', 'BUY1')->sole();
        $this->asCompany($this->company, fn () => app(GrainSettlements::class)->capture($this->company->id, ['party_id' => $this->buyer->id, 'reference' => 'AF-ST-1',
            'statement_date' => today()->toDateString(), 'period_from' => today()->subDay()->toDateString(), 'period_to' => today()->toDateString()],
            [['our_ticket_ref' => $one->number, 'buyer_ticket_ref' => 'AF-100', 'net_kg' => 30000, 'settled_kg' => 30000, 'grade' => 'WM1', 'price_per_t' => 4000, 'delivered_on' => today()->toDateString()],
                ['buyer_ticket_ref' => 'AF-999', 'vehicle_reg' => 'NOT OURS', 'net_kg' => 33000, 'settled_kg' => 33000, 'price_per_t' => 4000, 'delivered_on' => today()->toDateString()]], $this->manager));

        $r = $this->asCompany($this->company, fn () => app(GrainLoads::class)->reconcile());
        $codes = collect($r['items'])->groupBy('code');
        $this->assertSame(['LOST1'], $codes['lost']->pluck('vehicle')->all());
        $this->assertEqualsCanonicalizing(['BUY2', 'BUY3'], $codes['no_receipt']->pluck('vehicle')->all());
        $this->assertEqualsCanonicalizing(['BUY1', 'BUY3'], $codes['duplicate']->pluck('vehicle')->all(), 'Receipt AF-100 is on two tickets.');
        $this->assertSame(['AF-999'], $codes['unknown_receipt']->pluck('ticket')->all());
        $this->actingAs($this->manager)->get(route('agri.grain', 'reconciliation'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('data.counts.lost', 1));

        // A field load that turns up after all is weighed in and stops being lost; a void needs a reason and never touches a complete ticket.
        $lost = GrainTicket::withoutGlobalScopes()->where('vehicle_reg', 'LOST1')->sole();
        $this->actingAs($this->manager)->post(route('agri.grain.tickets.void', $one->uuid), ['reason' => 'x'])->assertSessionHasErrors('ticket');
        $this->actingAs($this->manager)->post(route('agri.grain.tickets.void', $lost->uuid), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('agri.grain.tickets.void', $lost->uuid), ['reason' => 'Truck broke down; load tipped back at the field.'])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('lost', $this->asCompany($this->company, fn () => app(GrainLoads::class)->reconcile())['counts']);
    }

    public function test_a_buyer_statement_is_matched_line_by_line_and_the_contract_balance_and_settlement_follow(): void
    {
        $c = $this->contract();
        $deliver = fn (string $reg, float $gross, float $moisture) => $this->actingAs($this->manager)->post(route('agri.grain.capture'), ['farm_id' => $this->farmA->uuid,
            'movement' => 'field_to_buyer', 'crop_plan_id' => $this->plan->uuid, 'party_id' => $this->buyer->id, 'contract_id' => $c->uuid, 'destination_name' => 'Afgri Bothaville silo',
            'vehicle_reg' => $reg, 'gross_kg' => $gross, 'tare_kg' => 14000, 'moisture_pct' => $moisture, 'foreign_matter_pct' => 0.2])->assertSessionHasNoErrors();
        $deliver('FS 1', 44000, 13);            // 30 t net; 13% → 171 kg shrink + 150 kg handling = 29 679 kg settled, WM1
        $deliver('FS 2', 46000, 12);            // 32 t net, dry, WM1
        [$t1, $t2] = GrainTicket::withoutGlobalScopes()->orderBy('id')->get()->all();
        $this->assertEquals(29679, $t1->settled_kg);
        $h = HarvestRecord::withoutGlobalScopes()->findOrFail($t1->harvest_record_id);
        $this->assertSame('sold', $h->destination);
        $this->assertNull($h->journal_id, 'Nothing is posted until the buyer settles.');

        // The contract counts the loads as deliveries.
        $bal = $this->asCompany($this->company, fn () => app(GrainSettlements::class)->contractBalance($c->fresh()));
        $this->assertEqualsWithDelta(61.679, $bal['delivered'], 0.0005);
        $this->assertEqualsWithDelta(38.321, $bal['outstanding'], 0.0005);
        $this->assertSame(2, $bal['awaiting_receipt']);
        $this->assertEqualsWithDelta(38.321, $this->asCompany($this->company, fn () => app(FarmSales::class)->position($c->fresh()))['remaining'], 0.0005);

        // The elevator's CSV: line 1 by our ticket number (10 kg out: within tolerance), line 2 found by truck, day and mass — graded WM2 by them.
        $csv = "Ticket,Your Ref,Vehicle,Date,Gross,Tare,Net,Moisture,Grade,Payable,Price,Amount\n"
            ."AF-201,{$t1->number},FS1,".today()->format('d/m/Y').",44010,14000,30010,13.0,WM1,29679,4000,118716.00\n"
            ."AF-202,,FS 2,".today()->format('d/m/Y').",46000,14000,32000,12.0,WM2,32000,3850,123200.00\n";
        $this->actingAs($this->manager)->post(route('agri.grain.settlements.import'), ['party_id' => $this->buyer->id, 'contract_id' => $c->uuid, 'reference' => 'AF-ST-77',
            'statement_date' => today()->toDateString(), 'charges' => 500, 'charges_note' => 'Grain levy', 'file' => UploadedFile::fake()->createWithContent('afgri.csv', $csv)])->assertSessionHasNoErrors();
        $s = GrainSettlement::withoutGlobalScopes()->sole();
        $this->assertSame('csv', $s->source);
        $this->assertSame($csv, base64_decode(DB::table('agri_grain_settlements')->where('id', $s->id)->value('file_data')), 'The imported file is kept in the database.');
        $lines = $s->lines()->get();
        $this->assertSame(['matched', 'differs'], $lines->pluck('match_status')->all());
        $this->assertSame([$t1->id, $t2->id], $lines->pluck('ticket_id')->all());
        $this->assertSame(['grade'], array_column($lines[1]->differences, 'field'), 'Only the grade differs: the WM2 price is the contract\'s discount.');
        $this->assertEquals(123200, $lines[1]->expected_amount);
        $this->assertSame('AF-201', $t1->fresh()->buyer_ticket_ref);
        // The same statement again is refused; the same receipt claimed twice is a duplicate.
        $this->actingAs($this->manager)->post(route('agri.grain.settlements.import'), ['party_id' => $this->buyer->id, 'reference' => 'AF-ST-77', 'statement_date' => today()->toDateString(),
            'file' => UploadedFile::fake()->createWithContent('afgri.csv', $csv)])->assertSessionHasErrors('reference');

        // The balance now follows what the buyer settled.
        $bal = $this->asCompany($this->company, fn () => app(GrainSettlements::class)->contractBalance($c->fresh()));
        $this->assertEqualsWithDelta(61.679, $bal['buyer_accepted'], 0.0005);
        $this->assertSame(0, $bal['awaiting_receipt']);
        $this->assertEquals(241916, $bal['paid_value']);

        // Posting goes the farm-sale way: Dr debtors / Cr produce sales with the farm and crop, the levy taken off.
        $this->actingAs($this->worker)->post(route('agri.grain.settlements.post', $s->uuid))->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.grain.settlements.post', $s->uuid))->assertSessionHasNoErrors();
        $s = $s->fresh();
        $this->assertSame('posted', $s->status);
        $this->assertCount(1, $s->journal_ids);
        $j = DB::table('fin_journals')->where('id', $s->journal_ids[0])->first();
        $this->assertSame('agri', $j->source_module);
        $this->assertSame('agri.sale', $j->event_type);
        $sales = DB::table('fin_journal_lines')->where('journal_id', $j->id)->where('credit', '>', 0)->first();
        $this->assertEquals(241416, (float) $sales->credit);
        $this->assertEquals(\App\Modules\Agri\Services\FarmSales::salesAccountId(), $sales->account_id);
        $dims = json_decode($sales->dimensions, true);
        $this->assertSame((string) $this->farmA->id, $dims['agri_farm']);
        $this->assertSame('crop_plan:'.$this->plan->id, $dims['agri_enterprise']);
        $debtors = DB::table('fin_journal_lines')->where('journal_id', $j->id)->where('debit', '>', 0)->first();
        $this->assertEquals(\App\Modules\Finance\Accounting\Models\FinAccount::withoutGlobalScopes()->where('code', config('finance.ar_control'))
            ->where(fn ($q) => $q->where('company_id', $this->company->id)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('id'), $debtors->account_id);
        // Posted once only.
        $this->actingAs($this->manager)->post(route('agri.grain.settlements.post', $s->uuid))->assertSessionHasErrors('settlement');

        $this->actingAs($this->manager)->get(route('agri.grain', 'settlements'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Grain/Index')->where('data.settlements.0.lines.1.match_status', 'differs'));
        $this->actingAs($this->manager)->get(route('agri.grain', 'contracts'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('data.contracts.0.reference', $c->reference));
    }

    public function test_a_typed_in_statement_line_that_claims_a_settled_load_again_is_a_duplicate_and_blocks_posting(): void
    {
        $c = $this->contract(['pricing' => 'pool', 'price' => null, 'formula' => ['pool_price' => 3900]]);
        $this->actingAs($this->manager)->post(route('agri.grain.capture'), ['farm_id' => $this->farmA->uuid, 'movement' => 'field_to_buyer', 'crop_plan_id' => $this->plan->uuid,
            'party_id' => $this->buyer->id, 'contract_id' => $c->uuid, 'vehicle_reg' => 'DUP 1', 'gross_kg' => 44000, 'tare_kg' => 14000, 'moisture_pct' => 12])->assertSessionHasNoErrors();
        $t = GrainTicket::withoutGlobalScopes()->sole();
        $line = ['our_ticket_ref' => $t->number, 'net_kg' => 30000, 'settled_kg' => 30000, 'price_per_t' => 3900, 'delivered_on' => today()->toDateString()];
        $this->actingAs($this->manager)->post(route('agri.grain.settlements.store'), ['party_id' => $this->buyer->id, 'reference' => 'S-1', 'statement_date' => today()->toDateString(),
            'lines' => [$line, $line]])->assertSessionHasNoErrors();
        $s = GrainSettlement::withoutGlobalScopes()->sole();
        $this->assertSame(['matched', 'duplicate'], $s->lines()->pluck('match_status')->all());
        $this->assertEquals(117000, $s->lines()->first()->expected_amount, 'The pool price for 30 t.');
        $this->actingAs($this->manager)->post(route('agri.grain.settlements.post', $s->uuid))->assertSessionHasErrors('settlement');
    }

    public function test_grain_screens_and_actions_need_their_permissions_and_another_organisation_sees_none_of_ours(): void
    {
        $t = $this->capture([]);
        // A worker records harvests but sells nothing: loads yes, settlements no; somebody with no farm permission, nothing.
        $this->actingAs($this->worker)->get(route('agri.grain', 'loads'))->assertOk();
        $this->actingAs($this->worker)->get(route('agri.grain', 'settlements'))->assertForbidden();
        $this->actingAs($this->worker)->post(route('agri.grain.settlements.store'), ['party_id' => $this->buyer->id, 'reference' => 'X', 'statement_date' => today()->toDateString(), 'lines' => [['net_kg' => 1]]])->assertForbidden();
        $this->actingAs($this->worker)->post(route('agri.grain.weighbridges.store'), ['farm_id' => $this->farmA->uuid, 'name' => 'x'])->assertForbidden();
        $this->actingAs($this->outsider)->get(route('agri.grain', 'loads'))->assertForbidden();
        $this->actingAs($this->outsider)->post(route('agri.grain.capture'), [])->assertForbidden();
        $this->actingAs($this->manager)->get(route('agri.grain.home'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Grain/Index')->where('data.tickets.0.number', $t->number)->where('can.record', true));

        $other = Company::create(['name' => 'Ander Boerdery']);
        $this->entitle($other);
        $role = Role::firstOrCreate(['name' => 'other grain owner', 'guard_name' => 'web']);
        $role->syncPermissions(RoleCatalog::everything());
        $them = $this->makeUser('owner@ander-grain.test', $role, $other);
        $this->member($them, $this->farm('ANDER', $other));
        $this->flushSession();
        foreach (['loads', 'bins', 'reconciliation', 'settlements', 'contracts'] as $tab) {
            $html = json_encode($this->actingAs($them)->get(route('agri.grain', $tab))->assertOk()->viewData('page')['props']);
            $this->assertStringNotContainsString($t->number, $html);
            $this->assertStringNotContainsString('Afgri Bothaville', $html);
        }
        $this->actingAs($them)->post(route('agri.grain.tickets.assess', $t->uuid), ['notes' => 'x'])->assertNotFound();
        $this->actingAs($them)->post(route('agri.grain.tickets.void', $t->uuid), ['reason' => 'x'])->assertNotFound();
        // Their weighbridge cannot weigh onto our ticket.
        [$bridge, $secret] = $this->asCompany($other, fn () => app(ClockDevices::class)->register(\App\Modules\Agri\Models\Farm::withoutGlobalScopes()->where('code', 'ANDER')->sole(), 'Theirs', 'weighbridge', $them));
        $r = $this->withToken($secret)->postJson(route('api.agri.devices.weighings', $bridge->uuid), ['weighings' => [['id' => 'x1', 'ticket' => $t->number, 'mass_kg' => 1000]]])->assertOk()->json('results.0');
        $this->assertFalse($r['ok']);
        $this->assertSame(2, GrainWeighing::withoutGlobalScopes()->where('ticket_id', $t->id)->count());
        // And the database itself refuses: under their session, our tickets are not there.
        $probe = function (int $companyId) use ($t) {
            DB::select("SELECT set_config('app.company_id', ?, true)", [(string) $companyId]);
            try {
                return [DB::table('agri_grain_tickets')->where('id', $t->id)->count(), DB::table('agri_grain_weighings')->where('ticket_id', $t->id)->count()];
            } finally {
                DB::select("SELECT set_config('app.company_id', '', true)");
            }
        };
        $this->assertSame([0, 0], $probe($other->id));
        $this->assertSame([1, 2], $probe($this->company->id));
    }
}
