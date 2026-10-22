<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\ImageryDropRule;
use App\Modules\Agri\Models\ImageryFlag;
use App\Modules\Agri\Services\CropImagery;

/**
 * An NDVI drop right after a lucerne cut, a mowing or a harvest is expected: no flag, and the
 * capture records why. Any other drop still flags, and an organisation's own rules replace the
 * built-in ones.
 */
class ImageryExpectedDropTest extends AgriTestCase
{
    private Farm $farmA;

    private FarmField $field;

    private CropPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->farmA = $this->farm('NORTH');
        $this->member($this->manager, $this->farmA);
        $this->member($this->worker, $this->farmA, 'worker');
        $this->field = $this->field($this->farmA, 'K2', 40);
        $this->plan = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'field_id' => $this->field->id, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId('LUCERNE'), 'code' => 'LUC-1', 'planned_area' => 40,
            'area_unit' => 'ha', 'planned_planting_on' => today()->subYear(), 'planted_on' => today()->subYear(), 'planned_harvest_on' => today()->addYears(3),
            'status' => CropPlan::PLANTED, 'yield_unit' => 'bales']));
        $this->capture(today()->subDays(20)->toDateString(), 0.72);
    }

    private function capture(string $on, float $mean): array
    {
        return $this->asCompany($this->company, fn () => app(CropImagery::class)->import($this->field, ['index' => 'ndvi', 'captured_on' => $on, 'source' => 'Sentinel-2 L2A',
            'mean' => $mean, 'cloud_pct' => 2], null));
    }

    private function mow(int $daysAgo, string $type = 'mowing'): void
    {
        $this->asCompany($this->company, fn () => FieldActivity::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'field_id' => $this->field->id, 'crop_plan_id' => $this->plan->id, 'activity_type' => $type, 'title' => 'Cut 4', 'planned_on' => today()->subDays($daysAgo),
            'status' => FieldActivity::COMPLETED, 'completed_at' => today()->subDays($daysAgo)->setTime(10, 0)]));
    }

    public function test_a_drop_after_a_cut_is_suppressed_and_says_why(): void
    {
        $this->mow(6);
        $r = $this->capture(today()->subDays(2)->toDateString(), 0.38);

        $this->assertLessThan(-15, $r['change_pct']);
        $this->assertNull($r['flag'], 'Lucerne just cut: the drop is expected.');
        $this->assertSame(0, ImageryFlag::withoutGlobalScopes()->count());
        $img = FieldImagery::withoutGlobalScopes()->where('captured_on', today()->subDays(2)->toDateString())->sole();
        $this->assertStringContainsString('mowing on '.today()->subDays(6)->toDateString(), $img->flag_suppressed);
        $this->assertStringContainsString('4 day(s) before this capture', $img->flag_suppressed);
    }

    public function test_a_drop_with_no_cut_or_a_cut_too_long_ago_still_flags(): void
    {
        $this->mow(60);
        $r = $this->capture(today()->subDays(2)->toDateString(), 0.38);
        $this->assertNotNull($r['flag']);
        $this->assertNull(FieldImagery::withoutGlobalScopes()->where('captured_on', today()->subDays(2)->toDateString())->value('flag_suppressed'));
    }

    public function test_a_recorded_harvest_counts_for_any_crop(): void
    {
        $this->asCompany($this->company, fn () => HarvestRecord::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'crop_plan_id' => $this->plan->id, 'field_id' => $this->field->id, 'harvested_on' => today()->subDays(3), 'quantity' => 900, 'unit' => 'bales', 'destination' => 'own_use']));
        $r = $this->capture(today()->subDays(1)->toDateString(), 0.30);
        $this->assertNull($r['flag']);
        $this->assertStringContainsString('harvest recorded on', (string) $r['suppressed']);
    }

    public function test_the_organisations_own_rules_replace_the_built_in_ones(): void
    {
        $this->actingAs($this->worker)->post(route('agri.satellite.drop-rules.store'), ['activity_types' => ['harvesting'], 'within_days' => 10])->assertForbidden();
        // Only maize harvests are expected drops now: the lucerne cut flags again.
        $this->actingAs($this->manager)->post(route('agri.satellite.drop-rules.store'), ['crop_type_id' => $this->cropTypeId('MAIZE-W'), 'activity_types' => ['harvesting'],
            'after_harvest' => true, 'within_days' => 10, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, ImageryDropRule::withoutGlobalScopes()->count());
        $this->mow(5);
        $this->assertNotNull($this->capture(today()->subDays(2)->toDateString(), 0.38)['flag']);

        // A lucerne rule of the organisation's own: within 7 days of mowing.
        $this->actingAs($this->manager)->post(route('agri.satellite.drop-rules.store'), ['crop_type_id' => $this->cropTypeId('LUCERNE'), 'activity_types' => ['mowing'],
            'after_harvest' => false, 'within_days' => 7, 'is_active' => true, 'note' => 'Lucerne regrows within three weeks'])->assertSessionHasNoErrors();
        $r = $this->capture(today()->toDateString(), 0.20);
        $this->assertNull($r['flag']);
        $this->assertStringContainsString('within 7 days', $r['suppressed']);

        $this->actingAs($this->admin)->get(route('agri.satellite.settings'))->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->component('Modules/Agri/Satellite/Settings')->has('drop_rules', 2)->has('default_drop_rules', 2)
                ->where('can_rules', true));
    }
}
