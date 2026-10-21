<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropInputTemplate;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\IrrigationException;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Agri\Models\ScoutingThreshold;
use App\Modules\Agri\Notifications\FarmWorkNotice;
use App\Modules\Agri\Services\CropProfitability;
use App\Modules\Agri\Services\InputPlanning;
use App\Modules\Agri\Services\Irrigation;
use App\Modules\Agri\Services\Scouting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 2 §3: precision crop and production management, part by part, ending with the section's
 * acceptance test — correcting a crop's actual input usage updates the cost reports while the
 * transaction history and the financial audit trail are kept.
 */
class PrecisionCropTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    private function plan($farm, $field, array $o = []): CropPlan
    {
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $o + [
            'farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(),
            'code' => 'MZ-1', 'planned_area' => 30, 'area_unit' => 'ha', 'planned_planting_on' => today()->toDateString(),
            'planned_harvest_on' => today()->addDays(140)->toDateString(), 'expected_yield' => 8, 'yield_unit' => 't', 'budget_amount' => 50000,
        ])->assertSessionHasNoErrors();

        return CropPlan::withoutGlobalScopes()->where('code', $o['code'] ?? 'MZ-1')->firstOrFail();
    }

    private function planted($farm, $field, array $o = []): CropPlan
    {
        $plan = $this->plan($farm, $field, $o);
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    public function test_3_1_fields_split_intercrops_share_ground_stages_rotation_and_soil_tests(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'L1', 40);
        $this->plan($farm, $field, ['code' => 'MZ-1', 'planned_area' => 40]);
        // The field is full — but an intercrop shares the ground.
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => 'BEAN-1', 'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => today()->toDateString(),
            'planned_harvest_on' => today()->addDays(90)->toDateString(), 'yield_unit' => 't'])->assertSessionHasErrors('planned_area');
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => 'BEAN-1', 'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => today()->toDateString(),
            'planned_harvest_on' => today()->addDays(90)->toDateString(), 'yield_unit' => 't', 'intercrop' => true])->assertSessionHasNoErrors();

        // Splitting: only an empty field, and the parts must fit.
        $other = $this->field($farm, 'L2', 60);
        $this->actingAs($this->admin)->post(route('agri.fields.split', $other->uuid), ['parts' => [['code' => 'L2A', 'name' => 'North', 'area' => 40], ['code' => 'L2B', 'name' => 'South', 'area' => 30]]])
            ->assertSessionHasErrors('parts');
        $this->actingAs($this->admin)->post(route('agri.fields.split', $other->uuid), ['parts' => [['code' => 'L2A', 'name' => 'North', 'area' => 35], ['code' => 'L2B', 'name' => 'South', 'area' => 25]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, FarmField::withoutGlobalScopes()->where('parent_field_id', $other->id)->count());
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), ['farm_id' => $farm->uuid, 'field_id' => $other->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ-2', 'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => today()->toDateString(),
            'planned_harvest_on' => today()->addDays(140)->toDateString(), 'yield_unit' => 't'])->assertSessionHasErrors('field_id');

        // Growth stages on a planted crop; the latest is the plan's.
        $plan = CropPlan::withoutGlobalScopes()->where('code', 'MZ-1')->first();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve']);
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant']);
        $this->actingAs($this->manager)->post(route('agri.crops.stages.store', $plan->uuid), ['stage' => 'emergence', 'observed_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.stages.store', $plan->uuid), ['stage' => 'vegetative', 'observed_on' => today()->addDay()->toDateString()])->assertSessionHasErrors('observed_on');
        $this->assertSame('emergence', $plan->fresh()->current_stage);

        // Rotation: the same crop again on the same field is called out.
        $r = $this->actingAs($this->manager)->getJson(route('agri.crops.rotation', ['field' => $field->uuid, 'crop_type_id' => $this->cropTypeId()]))->assertOk()->json();
        $this->assertNotEmpty($r['history']);
        $this->assertStringContainsString('follows', $r['warnings'][0]);

        // A soil test with its sampling point and the laboratory's report.
        $this->actingAs($this->manager)->post(route('agri.fields.soil-tests.store', $field->uuid), ['sampled_on' => today()->subWeek()->toDateString(),
            'laboratory' => 'Bemlab', 'ph' => 5.4, 'p_mg_kg' => 18, 'k_mg_kg' => 120, 'latitude' => -28.23, 'longitude' => 28.31,
            'report' => UploadedFile::fake()->createWithContent('lab.pdf', "%PDF-1.4\n%x\n")])->assertSessionHasNoErrors();
        $test = \App\Modules\Agri\Models\SoilTest::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->get(route('agri.soil-tests.report', $test->uuid))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_3_2_input_requirements_come_from_approved_rates_and_are_checked_against_stock_orders_and_budget(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'L1', 40);
        $seed = $this->stockItem('SEED', 'bag', 3000);
        $fert = $this->stockItem('MAP', 'kg', 14);
        $this->stockOnFarm($farm, $seed, 50, 3000);
        $plan = $this->plan($farm, $field);   // 30 ha, 8 t/ha expected
        $type = $plan->crop_type_id;
        $mk = fn (array $d) => $this->actingAs($this->manager)->post(route('agri.crops.templates.store'), $d + ['crop_type_id' => $type, 'basis' => 'per_ha'])->assertSessionHasNoErrors();
        $mk(['category' => 'seed', 'stock_item_id' => $seed->id, 'description' => 'Seed 80k', 'rate' => 1, 'unit' => 'bag', 'timing_days' => 0]);
        $mk(['category' => 'fertiliser', 'stock_item_id' => $fert->id, 'description' => 'MAP at planting', 'rate' => 150, 'unit' => 'kg', 'timing_days' => 0, 'guidance_ref' => 'FSSA 2025']);
        $mk(['category' => 'packaging', 'description' => 'Bags', 'rate' => 20, 'basis' => 'per_t_yield', 'unit' => 'bag']);

        // Drafts do not count, and the author cannot approve their own rate.
        $this->assertSame([], app(InputPlanning::class)->forPlan($plan)['lines']);
        $t = CropInputTemplate::withoutGlobalScopes()->get();
        $this->actingAs($this->manager)->post(route('agri.crops.templates.approve', $t[0]->uuid))->assertSessionHasErrors('template');
        foreach ($t as $x) {
            $this->actingAs($this->admin)->post(route('agri.crops.templates.approve', $x->uuid))->assertSessionHasNoErrors();
        }

        $lines = collect($this->asCompany($this->company, fn () => app(InputPlanning::class)->forPlan($plan->fresh()))['lines'])->keyBy('category');
        $this->assertEquals(30, $lines['seed']['quantity']);
        $this->assertSame('covered', $lines['seed']['status']);
        $this->assertEquals(4500, $lines['fertiliser']['quantity']);   // 150 kg × 30 ha
        $this->assertSame('to_order', $lines['fertiliser']['status']);
        $this->assertEquals(4800, $lines['packaging']['quantity']);    // 20 bags × 240 t
        $totals = $this->asCompany($this->company, fn () => app(InputPlanning::class)->forPlan($plan->fresh()))['totals'];
        $this->assertTrue($totals['over_budget'], '30 bags of seed at R3 000 and 4.5 t of MAP exceed the R50 000 budget.');
    }

    public function test_3_3_scouting_thresholds_escalation_and_ai_suggestions_need_qualified_review(): void
    {
        Notification::fake();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $field = $this->field($farm);
        $plan = $this->planted($farm, $field);
        $this->asCompany($this->company, fn () => ScoutingThreshold::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'kind' => 'pest',
            'min_severity' => 'medium', 'count' => 2, 'within_days' => 7, 'escalate_after_hours' => 24]));
        $observe = fn (array $o = []) => $this->actingAs($this->supervisor)->post(route('agri.crops.scouting.store'), $o + ['farm_id' => $farm->uuid,
            'crop_plan_id' => $plan->uuid, 'observed_on' => today()->toDateString(), 'kind' => 'pest', 'severity' => 'medium',
            'description' => 'Fall armyworm in the whorls', 'incidence_pct' => 12, 'affected_area_ha' => 3, 'latitude' => -28.2, 'longitude' => 28.3]);

        $observe(['photos' => [UploadedFile::fake()->image('leaf.jpg', 400, 300)]])->assertSessionHasNoErrors();
        $first = CropObservation::withoutGlobalScopes()->sole();
        $this->assertSame(1, $first->photos()->count());
        $this->assertFalse((bool) $first->action_required, 'One observation is below the threshold.');
        $observe()->assertSessionHasNoErrors();
        $second = CropObservation::withoutGlobalScopes()->latest('id')->first();
        $this->assertTrue((bool) $second->action_required);
        Notification::assertSentTo($this->manager, FarmWorkNotice::class, fn ($n) => $n->subject === 'Scouting threshold');

        // An AI suggestion must be reviewed by a qualified person before a treatment is recorded against it.
        $this->asCompany($this->company, fn () => $second->update(['ai_suggestion' => ['diagnoses' => [['name' => 'Fall armyworm', 'confidence' => 'high']], 'caveats' => 'Confirm larvae']]));
        $spray = $this->asCompany($this->company, fn () => FieldActivity::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'crop_plan_id' => $plan->id, 'field_id' => $field->id, 'activity_type' => 'spraying', 'title' => 'Spray FAW', 'planned_on' => today(), 'status' => 'planned']));
        $this->actingAs($this->supervisor)->post(route('agri.crops.scouting.treat', $second->uuid), ['activity_id' => $spray->uuid])->assertSessionHasErrors('treatment');
        $this->actingAs($this->supervisor)->post(route('agri.crops.scouting.review', $second->uuid), ['note' => 'Confirmed: larvae present'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.crops.scouting.review', $second->uuid), ['note' => 'Confirmed: larvae present'])->assertSessionHasNoErrors();
        $this->actingAs($this->supervisor)->post(route('agri.crops.scouting.treat', $second->uuid), ['activity_id' => $spray->uuid])->assertSessionHasNoErrors();
        $this->assertSame('actioned', $second->fresh()->status);

        // The first one, needing action and untouched past its time, is escalated once.
        $this->asCompany($this->company, fn () => $first->update(['action_required' => true, 'created_at' => now()->subDays(2)]));
        $this->assertSame(1, $this->asCompany($this->company, fn () => app(Scouting::class)->escalateOverdue($this->company->id)));
        $this->assertSame(0, $this->asCompany($this->company, fn () => app(Scouting::class)->escalateOverdue($this->company->id)));
        Notification::assertSentTo($this->admin, FarmWorkNotice::class, fn ($n) => $n->subject === 'Scouting escalated');
    }

    public function test_3_4_irrigation_is_costed_logs_the_pump_flags_deviations_and_measures_water_use_efficiency(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'L1', 30);
        $plan = $this->planted($farm, $field);
        // Planted well before the cycle below, whatever the time of day the test runs.
        $this->asCompany($this->company, fn () => $plan->update(['planted_on' => today()->subDays(10), 'planned_planting_on' => today()->subDays(10)]));
        $pump = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm,
            ['code' => 'PMP', 'name' => 'Borehole pump', 'machine_type' => 'pump', 'hours_reading' => 1000]));
        $this->actingAs($this->manager)->post(route('agri.crops.zones.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'code' => 'Z1',
            'name' => 'Pivot', 'system' => 'centre_pivot', 'pump_machine_id' => $pump->uuid, 'flow_m3_per_hour' => 120, 'water_cost_per_m3' => 1.5,
            'energy_kwh_per_hour' => 55, 'energy_cost_per_kwh' => 2.2])->assertSessionHasNoErrors();
        $zone = IrrigationZone::withoutGlobalScopes()->sole();

        $this->actingAs($this->manager)->post(route('agri.crops.irrigation.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'zone_id' => $zone->uuid,
            'planned_start' => now()->subHours(6)->toDateTimeString(), 'planned_minutes' => 240])->assertSessionHasNoErrors();
        $event = IrrigationEvent::withoutGlobalScopes()->sole();
        $this->assertEquals(480, $event->planned_m3);          // 120 m³/h × 4 h
        $this->assertSame($plan->id, (int) $event->crop_plan_id);

        // It ran six hours, not four: costed, the pump logged, and the overrun raised.
        $this->actingAs($this->manager)->post(route('agri.crops.irrigation.record', $event->uuid), ['actual_minutes' => 360])->assertSessionHasNoErrors();
        $event->refresh();
        $this->assertEquals(720, $event->actual_m3);
        $this->assertEqualsWithDelta(1080.0, $event->water_cost, 0.01);        // 720 m³ × R1.50
        $this->assertEqualsWithDelta(726.0, $event->energy_cost, 0.01);        // 6 h × 55 kWh × R2.20
        $this->assertEqualsWithDelta(1006.0, (float) FarmMachine::withoutGlobalScopes()->find($pump->id)->hours_reading, 0.01);
        $this->assertTrue(IrrigationException::withoutGlobalScopes()->where('kind', 'over_applied')->exists());

        $this->actingAs($this->manager)->post(route('agri.crops.moisture.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'vwc_pct' => 31.5, 'depth_cm' => 30])
            ->assertSessionHasNoErrors();
        $this->asCompany($this->company, fn () => HarvestRecord::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'crop_plan_id' => $plan->id, 'field_id' => $field->id, 'harvested_on' => today(), 'quantity' => 180, 'unit' => 't', 'destination' => 'own_use']));
        $w = $this->asCompany($this->company, fn () => app(Irrigation::class)->forPlan($plan->fresh()));
        $this->assertEquals(720, $w['m3']);
        $this->assertEquals(2.4, $w['mm']);                    // 720 m³ over 30 ha
        $this->assertEqualsWithDelta(0.25, $w['per_m3'], 0.0001);   // 180 t / 720 m³
    }

    public function test_3_5_forecasts_are_estimates_from_history_harvests_carry_lots_and_losses_and_commitments_are_met_once(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'L1', 30);
        $this->asCompany($this->company, fn () => CropType::withoutGlobalScopes()->whereKey($this->cropTypeId())->update(['container_name' => 'bag', 'container_capacity' => 0.05, 'load_capacity' => 30]));
        // Last season on this field: 9 t/ha measured.
        $past = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'field_id' => $field->id, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ-0', 'planned_area' => 20, 'area_unit' => 'ha',
            'planned_planting_on' => today()->subYear(), 'planned_harvest_on' => today()->subMonths(7), 'yield_unit' => 't', 'status' => 'completed']));
        $this->asCompany($this->company, fn () => HarvestRecord::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'crop_plan_id' => $past->id, 'field_id' => $field->id, 'harvested_on' => today()->subMonths(7), 'quantity' => 180, 'unit' => 't', 'destination' => 'own_use']));
        $plan = $this->planted($farm, $field, ['planned_area' => 10]);

        $this->actingAs($this->manager)->post(route('agri.crops.forecasts.store', $plan->uuid), ['method' => 'model'])->assertSessionHasNoErrors();
        $f = HarvestForecast::withoutGlobalScopes()->sole();
        $this->assertSame('model', $f->method);
        $this->assertEqualsWithDelta(90.0, $f->quantity, 0.001);     // 9 t/ha × 10 ha
        $this->assertStringContainsString('measured harvests on this field', $f->basis[0]);

        $this->actingAs($this->manager)->post(route('agri.crops.commitments.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid, 'buyer' => 'Afgri',
            'channel' => 'contract', 'quantity' => 40, 'unit' => 't', 'grade' => 'WM1', 'deliver_on' => today()->addDays(10)->toDateString(), 'price_per_unit' => 4200])
            ->assertSessionHasNoErrors();
        $c = HarvestCommitment::withoutGlobalScopes()->sole();

        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), ['harvested_on' => today()->toDateString(), 'quantity' => 42,
            'grade' => 'WM1', 'destination' => 'own_use', 'rejected_quantity' => 1.5, 'spoiled_quantity' => 0.5, 'field_loss_quantity' => 2,
            'containers' => 840, 'commitment_id' => $c->uuid])->assertSessionHasNoErrors();
        $h = HarvestRecord::withoutGlobalScopes()->where('crop_plan_id', $plan->id)->sole();
        $this->assertMatchesRegularExpression('/^MZ1-\d{8}-1$/', $h->lot_code);
        $this->assertSame('delivered', $c->fresh()->status);

        $o = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\HarvestPlanning::class)->overview($plan->fresh()));
        $this->assertTrue($o['forecast']['estimate']);
        $this->assertEquals(42, $o['measured']['harvested']);
        $this->assertEquals(40, $o['measured']['saleable']);
        $this->assertEquals(960, $o['logistics']['containers']);    // 48 t left ÷ 0.05 t a bag
        $this->assertEquals(2, $o['logistics']['loads']);            // 48 t ÷ 30 t a load
    }

    public function test_3_6_correcting_actual_input_usage_updates_crop_costs_and_keeps_the_history_and_audit_trail(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'L1', 30);
        $fert = $this->stockItem('LAN', 'kg', 10);
        $this->stockOnFarm($farm, $fert, 2000, 10);
        $plan = $this->planted($farm, $field);
        $this->asCompany($this->company, fn () => CropInputTemplate::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'crop_type_id' => $plan->crop_type_id,
            'category' => 'fertiliser', 'stock_item_id' => $fert->id, 'description' => 'LAN', 'rate' => 20, 'unit' => 'kg', 'status' => 'approved']));
        $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\OverheadRule::withoutGlobalScopes()->create(['company_id' => $this->company->id,
            'name' => 'Admin', 'basis' => 'per_ha', 'rate' => 100, 'valid_from' => today()->subYear()]));

        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid,
            'activity_type' => 'fertilising', 'title' => 'Top dress', 'planned_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $activity = FieldActivity::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $activity->uuid), ['stock_item_id' => $fert->id, 'quantity' => 600])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), ['action' => 'complete'])->assertSessionHasNoErrors();

        $profit = fn () => $this->asCompany($this->company, fn () => app(CropProfitability::class)->forPlan($plan->fresh()));
        $before = $profit();
        $this->assertEqualsWithDelta(6000.0, $before['costs']['fertiliser'], 0.01);
        $this->assertEqualsWithDelta(3000.0, $before['overheads']['total'], 0.01);   // R100 × 30 ha

        // Only 450 kg went on. The correction needs a reason.
        $input = ActivityInput::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.correct', $input->uuid), ['quantity' => 450, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.correct', $input->uuid), ['quantity' => 450, 'reason' => 'Spreader calibration: 450 kg went on'])
            ->assertSessionHasNoErrors();

        $after = $profit();
        $this->assertEqualsWithDelta(4500.0, $after['costs']['fertiliser'], 0.01, 'The cost report shows the corrected usage.');
        $this->assertEqualsWithDelta(1550.0, (float) $fert->fresh()->qty_on_hand, 0.001);
        // History: the original line is kept (reversed), the correction points at it, and the stock ledger holds all three movements.
        $lines = ActivityInput::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame(['reversed', 'issued'], $lines->pluck('status')->all());
        $this->assertSame($lines[0]->id, (int) $lines[1]->reversal_of_id);
        $moves = DB::table('fin_stock_movements')->where('item_id', $fert->id)->orderBy('id')->get();
        $this->assertCount(4, $moves, 'Opening stock, the original issue, its reversal and the corrected issue.');
        $this->assertSame($moves[1]->id, (int) $moves[2]->reversal_of_id);
        // The journal trail: the original posting, its reversal and the corrected posting all exist.
        $crop = DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_enterprise' = ?", ['crop_plan:'.$plan->id]);
        $this->assertGreaterThanOrEqual(3, (clone $crop)->count());
        $this->assertEqualsWithDelta(4500.0, (float) (clone $crop)->sum('debit') - (float) (clone $crop)->sum('credit'), 0.01);

        // Margins are worked out the same way for every plan.
        $this->assertEqualsWithDelta(-($after['direct_costs'] + $after['overheads']['total']), $after['net_margin'], 0.01);
        $this->assertSame($after['plan']['code'], $this->asCompany($this->company, fn () => app(CropProfitability::class)->compare(collect([$plan->fresh()]), 'field'))[0]['plans'][0]);
    }

    public function test_every_tab_and_the_plan_page_open_with_data(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm);
        $plan = $this->planted($farm, $field);
        $this->asCompany($this->company, fn () => CropObservation::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'field_id' => $field->id, 'crop_plan_id' => $plan->id, 'observed_on' => today(), 'kind' => 'pest', 'severity' => 'high', 'description' => 'Stalk borer', 'status' => 'open']));

        foreach (['scouting', 'irrigation', 'harvest', 'inputs', 'profitability'] as $tab) {
            $this->actingAs($this->admin)->get(route('agri.crops.precision', ['tab' => $tab, 'farm' => $farm->uuid]))->assertOk()
                ->assertInertia(fn ($p) => $p->component('Modules/Agri/Crops/Precision')->where('tab', $tab));
        }
        $this->actingAs($this->manager)->get(route('agri.crops.plans.show', $plan->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->has('precision.inputs.lines')->has('precision.harvest.measured')->has('precision.water'));
        // Profitability needs the finance view; a worker gets the crop tabs only.
        $this->member($this->worker, $farm, 'worker');
        $this->actingAs($this->worker)->get(route('agri.crops.precision', ['tab' => 'profitability']))->assertForbidden();
        $this->actingAs($this->worker)->get(route('agri.crops.precision', ['tab' => 'scouting']))->assertOk();
    }
}
