<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\OutcomeSnapshot;
use App\Modules\Agri\Services\FarmOutcomes;

/**
 * Phase 2 §19: outcome measures from the records, a baseline, and later periods set against it;
 * a measure without data reads as no data, never as zero.
 */
class OutcomesTest extends AgriTestCase
{
    public function test_outcomes_are_measured_and_compared_with_the_baseline(): void
    {
        $this->catalogue();
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm);
        $plan = $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'field_id' => $field->id, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ-1', 'planned_area' => 1, 'status' => 'harvesting',
            'planned_planting_on' => today()->subMonths(8), 'planned_harvest_on' => today()]));
        $harvest = fn (string $on, float $kg, float $ha, float $rejected) => $this->asCompany($this->company, fn () => HarvestRecord::withoutGlobalScopes()->create(['company_id' => $this->company->id,
            'farm_id' => $farm->id, 'field_id' => $field->id, 'crop_plan_id' => $plan->id, 'harvested_on' => $on, 'quantity' => $kg, 'unit' => 'kg', 'area_harvested' => $ha, 'rejected_quantity' => $rejected, 'destination' => 'own_use']));

        $harvest(today()->subMonths(4)->toDateString(), 6000, 1, 600);
        $m = $this->asCompany($this->company, fn () => app(FarmOutcomes::class)->measure($farm, today()->subMonths(5)->toDateString(), today()->subMonths(3)->toDateString()));
        $this->assertEqualsWithDelta(6000.0, $m['yield_per_ha']['value'], 0.01);
        $this->assertEqualsWithDelta(90.0, $m['saleable_yield']['value'], 0.01);
        $this->assertNull($m['fuel_per_hour']['value'], 'No machine hours: no data, not zero.');
        $this->assertNotEmpty($m['yield_per_ha']['how']);

        $this->actingAs($this->manager)->post(route('agri.outcomes.store'), ['farm_id' => $farm->uuid, 'from' => today()->subMonths(5)->toDateString(),
            'to' => today()->subMonths(3)->toDateString(), 'baseline' => true, 'note' => 'Before Mkhaya Agri'])->assertSessionHasNoErrors();
        $harvest(today()->subDays(10)->toDateString(), 7200, 1, 360);
        $this->actingAs($this->manager)->post(route('agri.outcomes.store'), ['farm_id' => $farm->uuid, 'from' => today()->subMonth()->toDateString(), 'to' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(1, OutcomeSnapshot::withoutGlobalScopes()->where('is_baseline', true)->count());

        $cmp = collect($this->asCompany($this->company, fn () => app(FarmOutcomes::class)->compare($farm))['rows'])->keyBy('key');
        $this->assertEqualsWithDelta(1200.0, $cmp['yield_per_ha']['change'], 0.01);
        $this->assertEqualsWithDelta(20.0, $cmp['yield_per_ha']['change_pct'], 0.01);
        $this->assertEqualsWithDelta(5.0, $cmp['saleable_yield']['change'], 0.01);
        $this->actingAs($this->manager)->get(route('agri.outcomes.index', ['farm' => $farm->uuid]))->assertOk();
    }
}
