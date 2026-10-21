<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\ImageryFlag;
use App\Modules\Agri\Models\Simulation;
use App\Modules\Agri\Services\FarmTwin;

/**
 * Phase 2 §16: the farm map carries each thing's live state and lists what it cannot place;
 * imagery flags become field checks with a track record; and the simulator compares plans with
 * every assumption stated, a chosen one becoming a draft crop plan.
 */
class TwinImagerySimulatorTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    public function test_the_map_shows_live_state_and_lists_what_has_no_coordinates(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $f1 = $this->field($farm, 'F1');
        $f2 = $this->field($farm, 'F2');
        $this->asCompany($this->company, fn () => $f1->update(['boundary' => ['type' => 'Polygon', 'coordinates' => [[[28.0, -26.0], [28.01, -26.0], [28.01, -26.01], [28.0, -26.01], [28.0, -26.0]]]]]));
        $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $f1->id,
            'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ-1', 'planned_area' => 10, 'status' => 'planted',
            'planned_planting_on' => today()->subMonth(), 'planned_harvest_on' => today()->addMonths(3)]));
        $this->asCompany($this->company, fn () => CropObservation::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $f1->id,
            'observed_on' => today(), 'kind' => 'pest', 'severity' => 'high', 'description' => 'Fall armyworm', 'status' => 'open']));
        $tractor = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'T1', 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 1]));
        $this->asCompany($this->company, fn () => $tractor->forceFill(['last_latitude' => -26.005, 'last_longitude' => 28.005, 'last_seen_at' => now()])->save());

        $t = $this->asCompany($this->company, fn () => app(FarmTwin::class)->build($farm));
        $field = collect($t['features'])->firstWhere('properties.label', 'F1')['properties'];
        $this->assertSame('MZ-1', $field['plan']);
        $this->assertSame('attention', $field['state'], 'A serious open observation shows on the field.');
        $this->assertSame(1, $field['serious']);
        $this->assertNotNull(collect($t['features'])->firstWhere('properties.label', 'T1'));
        $this->assertSame('F2', collect($t['unplaced'])->firstWhere('layer', 'field')['label'], 'No coordinates: listed, not guessed.');
        $this->assertEqualsWithDelta(28.0, $t['bounds'][0], 0.0001);
        $this->actingAs($this->manager)->get(route('agri.twin', ['tab' => 'map', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_imagery_drops_become_field_checks_and_build_a_track_record(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'F1');
        $post = fn (array $d) => $this->actingAs($this->manager)->post(route('agri.twin.imagery.store'), $d + ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'source' => 'Sentinel-2', 'index' => 'ndvi']);

        $post(['captured_on' => today()->subDays(10)->toDateString(), 'mean' => 0.72, 'cells' => [['id' => 'a', 'value' => 0.74], ['id' => 'b', 'value' => 0.70]]])->assertSessionHasNoErrors();
        // A cloudy capture is kept, never flags.
        $post(['captured_on' => today()->subDays(5)->toDateString(), 'mean' => 0.40, 'cloud_pct' => 70])->assertSessionHasNoErrors();
        $this->assertSame(0, ImageryFlag::withoutGlobalScopes()->count());
        // The field mean holds but zone b has dropped: a flag, and a scouting check to go and look.
        $post(['captured_on' => today()->toDateString(), 'mean' => 0.66, 'cells' => [['id' => 'a', 'value' => 0.73], ['id' => 'b', 'value' => 0.52, 'lat' => -26.0, 'lng' => 28.0]]])->assertSessionHasNoErrors();
        $flag = ImageryFlag::withoutGlobalScopes()->sole();
        $this->assertStringContainsString('zone', $flag->reason);
        $obs = CropObservation::withoutGlobalScopes()->findOrFail($flag->observation_id);
        $this->assertStringContainsString('imagery shows where, not what', $obs->description);
        $this->assertSame('open', $obs->status);

        $this->actingAs($this->manager)->post(route('agri.twin.flags.validate', $flag->uuid), ['outcome' => 'false_alarm', 'note' => 'Irrigation line was off for a day; crop fine'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $obs->fresh()->status);
        $track = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\CropImagery::class)->track($farm->id));
        $this->assertSame(0.0, $track['precision_pct']);
        $this->actingAs($this->manager)->get(route('agri.twin', ['tab' => 'imagery', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_the_simulator_compares_plans_with_stated_assumptions_and_a_choice_becomes_a_draft_plan(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'F1', 20);
        // Three past crops on this farm: 7, 8 and 9 t/ha, two of them planted in November.
        foreach ([[7, '2023-11-10'], [8, '2024-11-12'], [9, '2025-12-05']] as $i => [$tpha, $planted]) {
            $p = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $field->id,
                'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => "OLD-{$i}", 'planned_area' => 10, 'status' => 'completed',
                'planned_planting_on' => $planted, 'planted_on' => $planted, 'planned_harvest_on' => \Illuminate\Support\Carbon::parse($planted)->addMonths(5)]));
            $this->asCompany($this->company, fn () => HarvestRecord::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'crop_plan_id' => $p->id,
                'field_id' => $field->id, 'harvested_on' => \Illuminate\Support\Carbon::parse($planted)->addMonths(5), 'quantity' => $tpha * 10, 'unit' => 't', 'destination' => 'own_use']));
        }

        $this->actingAs($this->manager)->post(route('agri.twin.simulations.store'), ['farm_id' => $farm->uuid, 'name' => 'Maize 2026', 'field_id' => $field->uuid, 'crop_type_id' => $this->cropTypeId(),
            'base' => ['hectares' => 20, 'planting_date' => '2026-11-15', 'yield_per_ha' => 8, 'yield_unit' => 't', 'price' => 4000, 'inputs_per_ha' => 12000,
                'labour_hours_per_ha' => 10, 'labour_rate' => 40, 'water_required_m3_per_ha' => 4000, 'water_cost_per_m3' => 1, 'payment_days' => 30, 'days_to_maturity' => 150],
            'scenarios' => [['name' => 'Half the water', 'changes' => ['water_available_m3_per_ha' => 2000]], ['name' => 'Price falls 20%', 'changes' => ['price' => 3200]],
                ['name' => 'Plant in December', 'changes' => ['planting_date' => '2026-12-05']]]])->assertSessionHasNoErrors();
        $sim = Simulation::withoutGlobalScopes()->sole();
        $r = collect($sim->results['scenarios'])->keyBy('name');

        // Base: planted in November, which on this farm averaged 7.5 against 8 t/ha.
        $this->assertEqualsWithDelta(8 * 7.5 / 8, $r['Base plan']['yield_per_ha'], 0.001);
        $this->assertStringContainsString('November', $r['Base plan']['assumptions'][0]);
        $this->assertEqualsWithDelta(20 * (7.5 * 4000 - (12000 + 400 + 4000)), $r['Base plan']['margin'], 0.5);
        $this->assertEqualsWithDelta(16400 / 4000, $r['Base plan']['breakeven_yield_per_ha'], 0.001);
        // Half the water: yield to √0.5 with the reason stated.
        $this->assertEqualsWithDelta(7.5 * sqrt(0.5), $r['Half the water']['yield_per_ha'], 0.001);
        $this->assertTrue(collect($r['Half the water']['assumptions'])->contains(fn ($a) => str_contains($a, 'square-root')));
        $this->assertLessThan($r['Base plan']['margin'], $r['Price falls 20%']['margin']);
        // December has only one past crop: no planting-date effect, and it says so.
        $this->assertEqualsWithDelta(8.0, $r['Plant in December']['yield_per_ha'], 0.001);
        $this->assertStringContainsString('too few', $r['Plant in December']['assumptions'][0]);
        // Risk from the farm's own spread, funding need, labels.
        $this->assertLessThan($r['Base plan']['margin'], $r['Base plan']['margin_poor_year']);
        $this->assertGreaterThan(0, $r['Base plan']['peak_funding']);
        $this->assertStringContainsString('not a forecast', $r['Base plan']['label']);

        $season = $this->seasonId();
        $this->actingAs($this->manager)->post(route('agri.twin.simulations.adopt', $sim->uuid), ['scenario' => 'Plant in December', 'season_id' => $season])->assertRedirect();
        $plan = CropPlan::withoutGlobalScopes()->findOrFail($sim->fresh()->crop_plan_id);
        $this->assertSame(CropPlan::DRAFT, $plan->status, 'A draft, approved the usual way.');
        $this->assertSame('2026-12-05', $plan->planned_planting_on->toDateString());
        $this->assertEquals(4000, $plan->expected_price);
        $this->actingAs($this->manager)->get(route('agri.twin', ['tab' => 'simulator', 'farm' => $farm->uuid]))->assertOk();
    }
}
