<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Services\FarmDashboards;

/**
 * Phase 2 §13: a dashboard for each role, every figure labelled real-time, actual, estimate or
 * forecast with its source and drill-down link; the operational alerts; and exports to CSV and
 * Excel that respect the same access.
 */
class DashboardsTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->openBooks();
    }

    public function test_each_role_gets_its_dashboard_with_labelled_figures_operational_alerts_and_exports(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->worker, $farm, 'worker');
        $field = $this->field($farm);
        $plan = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $field->id,
            'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ-1', 'planned_area' => 10, 'status' => 'planted', 'expected_yield' => 8, 'yield_unit' => 't',
            'planned_planting_on' => today()->subMonths(3), 'planned_harvest_on' => today()->addMonth()]));
        // Forecast 60 t, but 70 t promised to buyers.
        $this->asCompany($this->company, fn () => HarvestForecast::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'crop_plan_id' => $plan->id,
            'made_on' => today(), 'quantity' => 60, 'unit' => 't', 'method' => 'manual', 'basis' => 'test']));
        $this->asCompany($this->company, fn () => HarvestCommitment::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'crop_plan_id' => $plan->id,
            'crop_type_id' => $plan->crop_type_id, 'buyer' => 'Mill', 'quantity' => 70, 'unit' => 't', 'deliver_on' => today()->addMonth(), 'price_per_unit' => 4000, 'delivered_quantity' => 0, 'status' => 'open']));
        // A job starting tomorrow with nobody on it; an expiring permit.
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), ['farm_id' => $farm->uuid, 'category' => 'harvesting', 'title' => 'Harvest MZ-1', 'priority' => 'high',
            'planned_start' => now()->addDay()->toDateTimeString(), 'planned_end' => now()->addDay()->addHours(8)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.work-orders.transition', WO::withoutGlobalScopes()->sole()->uuid), ['to' => WO::APPROVED])->assertSessionHasNoErrors();
        $this->fileDocument($farm, 'water_use_licence', 'Water licence', today()->addDays(10)->toDateString());

        // The roles each person may open.
        $roles = fn ($u) => array_column($this->asCompany($this->company, fn () => app(FarmDashboards::class)->rolesFor($u)), 'key');
        $this->assertContains('owner', $roles($this->manager));
        $this->assertNotContains('owner', $roles($this->worker));
        $this->assertContains('worker', $roles($this->worker));
        $this->actingAs($this->worker)->get(route('agri.insights', 'owner'))->assertForbidden();
        $this->actingAs($this->worker)->get(route('agri.insights', 'worker'))->assertOk();

        $page = $this->actingAs($this->manager)->get(route('agri.insights', 'owner'))->assertOk()->viewData('page')['props']['dashboard'];
        $this->assertEqualsCanonicalizing(['financial', 'production', 'workforce', 'assets'], array_column($page['sections'], 'key'));
        foreach ($page['sections'] as $s) {
            foreach ($s['metrics'] as $m) {
                $this->assertContains($m['nature'], ['real_time', 'actual', 'estimate', 'forecast'], $m['label']);
                $this->assertNotEmpty($m['source'], $m['label']);
            }
        }
        $kinds = array_column($page['alerts'], 'kind');
        $this->assertContains('harvest_short', $kinds);
        $this->assertContains('wo_unstaffed', $kinds);
        $this->assertContains('document', $kinds);
        $short = collect($page['alerts'])->firstWhere('kind', 'harvest_short');
        $this->assertSame('forecast', $short['nature']);
        $this->assertStringContainsString($plan->uuid, $short['link']);

        foreach (['operations', 'agronomist', 'supervisor', 'finance'] as $r) {
            $this->actingAs($this->manager)->get(route('agri.insights', $r))->assertOk();
        }

        // Exports.
        $csv = $this->actingAs($this->manager)->get(route('agri.insights.export', ['owner', 'production']))->assertOk();
        $this->assertStringContainsString('MZ-1', $csv->streamedContent());
        $xlsx = $this->actingAs($this->manager)->get(route('agri.insights.export', ['owner', 'alerts', 'format' => 'xlsx']))->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent(), 'An Excel workbook.');
        $this->actingAs($this->worker)->get(route('agri.insights.export', ['owner', 'alerts']))->assertForbidden();
        $this->actingAs($this->manager)->get(route('agri.reports.export', ['yield', 'format' => 'xlsx']))->assertOk();
    }
}
