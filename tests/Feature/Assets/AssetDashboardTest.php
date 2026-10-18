<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\MaintenanceStatus;
use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\Assets;
use Inertia\Testing\AssertableInertia as Assert;

class AssetDashboardTest extends AssetTestCase
{
    public function test_dashboard_renders_with_required_stat_props(): void
    {
        Assets::factory()->count(3)->create();

        $this->actingAs($this->assetManager)
            ->get(route('assets.dashboard.index'))
            ->assertOk()
            ->assertInertia(fn(Assert $page) =>
                $page->component('Modules/Assets/Dashboard/Index')
                     ->has('overview')
                     ->has('overview.total')
                     ->has('overview.fleet_value')
                     ->has('overview.under_maintenance')
                     ->has('overview.overdue_maintenance')
                     ->has('overview.expired_warranties')
                     ->has('maintenance')
                     ->has('incidents')
                     ->has('top_risk_assets')
                     ->has('utilization')
                     ->has('recent_activity')
                     ->has('assets_by_status')
                     ->has('assets_by_category')
            );
    }

    public function test_dashboard_overview_total_matches_asset_count(): void
    {
        Assets::factory()->count(5)->create();

        $this->actingAs($this->assetManager)
            ->get(route('assets.dashboard.index'))
            ->assertInertia(fn(Assert $page) =>
                $page->where('overview.total', 5)
            );
    }

    public function test_dashboard_overdue_maintenance_counts_scheduled_past_due(): void
    {
        $asset = Assets::factory()->create();

        AssetMaintenanceSchedule::factory()->create([
            'asset_id' => $asset->id,
            'status'   => MaintenanceStatus::Scheduled,
            'due_date' => now()->subDays(5)->toDateString(),
        ]);
        AssetMaintenanceSchedule::factory()->create([
            'asset_id' => $asset->id,
            'status'   => MaintenanceStatus::Scheduled,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->actingAs($this->assetManager)
            ->get(route('assets.dashboard.index'))
            ->assertInertia(fn(Assert $page) =>
                $page->where('overview.overdue_maintenance', 1)
            );
    }

    public function test_dashboard_feed_endpoint_returns_json_activity(): void
    {
        $asset = Assets::factory()->create();
        AssetCustodyLog::factory()->create([
            'asset_id'    => $asset->id,
            'action'      => 'registered',
            'occurred_at' => now()->subHours(2),
            'performed_by' => $this->assetManager->id,
        ]);

        $response = $this->actingAs($this->assetManager)
            ->getJson(route('assets.dashboard.feed'));

        $response->assertOk()
            ->assertJsonStructure(['activity']);

        $payload = $response->json();
        $this->assertIsArray($payload['activity']);
        $this->assertNotEmpty($payload['activity']);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('assets.dashboard.index'))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_feed_only_returns_logs_in_last_seven_days(): void
    {
        $asset = Assets::factory()->create();

        AssetCustodyLog::factory()->create([
            'asset_id'     => $asset->id,
            'action'       => 'registered',
            'occurred_at'  => now()->subDays(1),
            'performed_by' => $this->assetManager->id,
        ]);
        AssetCustodyLog::factory()->create([
            'asset_id'     => $asset->id,
            'action'       => 'moved',
            'occurred_at'  => now()->subDays(30),
            'performed_by' => $this->assetManager->id,
        ]);

        $response = $this->actingAs($this->assetManager)
            ->getJson(route('assets.dashboard.feed'));

        $response->assertOk();
        $this->assertCount(1, $response->json('activity'));
    }
}
