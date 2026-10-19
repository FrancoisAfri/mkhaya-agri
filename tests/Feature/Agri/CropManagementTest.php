<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Support\Facades\DB;

/**
 * Spec Module B: crop plans, field activities with inputs from the farm store, and harvests.
 */
class CropManagementTest extends AgriTestCase
{
    private function planPayload($farm, $field, array $o = []): array
    {
        return $o + [
            'farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(),
            'code' => 'MZ-25', 'planned_area' => 30, 'area_unit' => 'ha',
            'planned_planting_on' => today()->toDateString(), 'planned_harvest_on' => today()->addDays(140)->toDateString(),
            'expected_yield' => 8, 'yield_unit' => 't', 'budget_amount' => 250000,
        ];
    }

    private function approvedPlan($farm, $field, array $o = []): CropPlan
    {
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $this->planPayload($farm, $field, $o))->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->where('code', $o['code'] ?? 'MZ-25')->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    public function test_a_plan_cannot_take_more_of_a_field_than_is_left_for_that_period(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'F1', 50);

        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $this->planPayload($farm, $field))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $this->planPayload($farm, $field, ['code' => 'MZ-26', 'planned_area' => 25]))
            ->assertSessionHasErrors('planned_area');
        // 20 ha still fits next to the first 30.
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $this->planPayload($farm, $field, ['code' => 'SOY-25', 'planned_area' => 20, 'crop_type_id' => $this->cropTypeId('SOY')]))
            ->assertSessionHasNoErrors();
    }

    public function test_dates_must_be_in_order_and_inside_the_season(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm);

        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $this->planPayload($farm, $field, ['planned_harvest_on' => today()->subDay()->toDateString()]))
            ->assertSessionHasErrors('planned_harvest_on');
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $this->planPayload($farm, $field, ['planned_planting_on' => today()->addYears(3)->toDateString(), 'planned_harvest_on' => today()->addYears(4)->toDateString()]))
            ->assertSessionHasErrors('planned_planting_on');
    }

    public function test_the_drafter_cannot_approve_their_own_plan_and_nothing_is_planted_before_approval(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm);
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $this->planPayload($farm, $field))->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasErrors('status');
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasErrors('status');
        // Somebody without the approve permission is refused outright.
        $this->member($this->worker, $farm, 'worker');
        $this->actingAs($this->worker)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();
        $this->assertSame(CropPlan::PLANTED, $plan->fresh()->status);
    }

    public function test_completing_an_activity_issues_its_inputs_from_the_farm_store_once_with_farm_dimensions(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm);
        $fert = $this->stockItem('LAN-28', 'kg', 9.5);
        $this->stockOnFarm($farm, $fert, 2000, 9.5);
        $plan = $this->approvedPlan($farm, $field);

        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), [
            'farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid, 'activity_type' => 'fertilising',
            'title' => 'Top dress LAN', 'planned_on' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $activity = FieldActivity::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($field->id, (int) $activity->field_id, 'The plan\'s field is used when none is given.');

        $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $activity->uuid), ['stock_item_id' => $fert->id, 'quantity' => 600, 'rate_per_ha' => 20])
            ->assertSessionHasNoErrors();

        $complete = ['action' => 'complete', 'labour_hours' => 6, 'labour_cost' => 900, 'area_covered' => 30];
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), $complete)->assertSessionHasNoErrors();
        // A second "complete" (double tap) is refused and consumes nothing more.
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), $complete)->assertSessionHasErrors('status');

        $input = ActivityInput::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('issued', $input->status);
        $this->assertEqualsWithDelta(5700.0, (float) $input->total_cost, 0.01);
        $this->assertEqualsWithDelta(1400.0, (float) FinStockItem::withoutGlobalScopes()->find($fert->id)->qty_on_hand, 0.001);
        $this->assertSame(1, DB::table('fin_stock_movements')->where('source_type', 'agri_activity')->count());

        // The cost sits on the shared ledger, on the farm, field and crop it was used on.
        $line = DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_enterprise' = ?", ['crop_plan:'.$plan->id])->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(5700.0, (float) $line->debit, 0.01);
        $dims = json_decode($line->dimensions, true);
        $this->assertSame((string) $farm->id, $dims['agri_farm']);
        $this->assertSame((string) $field->id, $dims['agri_field']);
        $this->assertSame('NORTH', $dims['tracking']);
    }

    public function test_an_activity_cannot_issue_more_than_the_farm_store_holds_and_records_nothing_when_it_fails(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->approvedPlan($farm, $this->field($farm));
        $seed = $this->stockItem('SEED-MZ', 'bag', 3200);
        $this->stockOnFarm($farm, $seed, 5, 3200);

        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid,
            'activity_type' => 'planting', 'title' => 'Plant', 'planned_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $activity = FieldActivity::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $activity->uuid), ['stock_item_id' => $seed->id, 'quantity' => 12])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), ['action' => 'complete'])->assertSessionHasErrors('inputs');
        $this->assertSame('planned', $activity->fresh()->status);
        $this->assertSame('planned', ActivityInput::withoutGlobalScopes()->first()->status);
    }

    public function test_harvest_inside_a_withholding_period_is_refused_unless_an_approver_overrides_with_a_reason(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $plan = $this->approvedPlan($farm, $this->field($farm));
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant', 'date' => today()->subDays(100)->toDateString()]);
        $chem = $this->stockItem('CHEM-X', 'l', 450);
        $this->stockOnFarm($farm, $chem, 50, 450);

        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid,
            'activity_type' => 'spraying', 'title' => 'Fungicide', 'planned_on' => today()->toDateString()]);
        $activity = FieldActivity::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $activity->uuid), ['stock_item_id' => $chem->id, 'quantity' => 10, 'withholding_days' => 14]);
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), ['action' => 'complete'])->assertSessionHasNoErrors();

        $harvest = ['harvested_on' => today()->toDateString(), 'quantity' => 40, 'unit' => 't', 'destination' => 'own_use'];
        // A supervisor (no approve permission) cannot override.
        $this->actingAs($this->supervisor)->post(route('agri.crops.harvests.store', $plan->uuid), $harvest + ['override_reason' => 'Rain coming'])
            ->assertSessionHasErrors('harvested_on');
        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), $harvest)->assertSessionHasErrors('harvested_on');
        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), $harvest + ['override_reason' => 'Lab residue test passed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Lab residue test passed', HarvestRecord::withoutGlobalScopes()->first()->override_reason);
        $this->assertSame(CropPlan::HARVESTING, $plan->fresh()->status);
    }

    public function test_harvest_into_the_store_becomes_stock_and_yield_is_measured_against_plan(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->approvedPlan($farm, $this->field($farm));
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant', 'date' => today()->subDays(120)->toDateString()]);
        $grain = $this->stockItem('MAIZE-GRAIN', 't', 0);

        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), [
            'harvested_on' => today()->toDateString(), 'quantity' => 210, 'unit' => 't', 'destination' => 'store',
            'stock_item_id' => $grain->id, 'unit_value' => 3900, 'moisture_pct' => 12.5,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(210.0, (float) FinStockItem::withoutGlobalScopes()->find($grain->id)->qty_on_hand, 0.001);
        $y = app(\App\Modules\Agri\Services\Harvests::class)->yieldFor($plan->fresh());
        $this->assertEqualsWithDelta(7.0, $y['per_ha'], 0.001);   // 210 t on 30 ha
        $this->assertEqualsWithDelta(-12.5, $y['variance_pct'], 0.01); // against 8 t/ha planned

        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'complete'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'cancel', 'reason' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_crop_records_on_another_farm_are_not_found_and_a_viewer_membership_is_read_only(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->manager, $south);
        $planNorth = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'farm_id' => $north->id, 'field_id' => $this->field($north)->id, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => 'N1', 'planned_area' => 5, 'planned_planting_on' => today(), 'planned_harvest_on' => today()->addMonths(4),
        ]));

        $this->actingAs($this->manager)->get(route('agri.crops.plans.show', $planNorth->uuid))->assertNotFound();
        $codes = array_column($this->actingAs($this->manager)->get(route('agri.crops.index', ['status' => 'all']))->viewData('page')['props']['plans'], 'code');
        $this->assertNotContains('N1', $codes);

        $this->member($this->supervisor, $north, 'viewer');
        $this->actingAs($this->supervisor)->get(route('agri.crops.plans.show', $planNorth->uuid))->assertOk();
        $this->actingAs($this->supervisor)->post(route('agri.crops.activities.store'), ['farm_id' => $north->uuid, 'activity_type' => 'scouting',
            'title' => 'Walk', 'planned_on' => today()->toDateString()])->assertForbidden();
    }
}
