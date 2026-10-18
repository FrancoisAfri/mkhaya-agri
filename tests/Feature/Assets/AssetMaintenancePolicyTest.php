<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\MaintenanceStatus;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\Assets;

/**
 * Covers permission/role enforcement on maintenance endpoints.
 *
 * The middleware contract: assets.maintenance + assets.maintenance.create on store,
 * assets.maintenance.complete on complete. Viewer (assets.view only) must be denied.
 */
class AssetMaintenancePolicyTest extends AssetTestCase
{
    public function test_manager_can_schedule_maintenance(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.store', $asset->uuid), [
                'title'            => 'Annual service',
                'maintenance_type' => 'preventive',
                'due_date'         => now()->addDays(30)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('asset_maintenance_schedules', [
            'asset_id' => $asset->id,
            'title'    => 'Annual service',
        ]);
    }

    public function test_viewer_cannot_schedule_maintenance(): void
    {
        $asset = Assets::factory()->create();

        $response = $this->actingAs($this->assetViewer)
            ->post(route('assets.maintenance.store', $asset->uuid), [
                'title'            => 'Service',
                'maintenance_type' => 'preventive',
                'due_date'         => now()->addDays(30)->toDateString(),
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('asset_maintenance_schedules', [
            'asset_id' => $asset->id,
            'title'    => 'Service',
        ]);
    }

    public function test_manager_can_complete_maintenance(): void
    {
        $asset    = Assets::factory()->create();
        $schedule = AssetMaintenanceSchedule::factory()->create([
            'asset_id' => $asset->id,
            'status'   => MaintenanceStatus::Scheduled,
        ]);

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.complete', [$asset->uuid, $schedule->id]), [
                'action_taken' => 'Service done',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('asset_maintenance_schedules', [
            'id'     => $schedule->id,
            'status' => MaintenanceStatus::Completed->value,
        ]);
    }

    public function test_viewer_cannot_complete_maintenance(): void
    {
        $asset    = Assets::factory()->create();
        $schedule = AssetMaintenanceSchedule::factory()->create([
            'asset_id' => $asset->id,
            'status'   => MaintenanceStatus::Scheduled,
        ]);

        $response = $this->actingAs($this->assetViewer)
            ->post(route('assets.maintenance.complete', [$asset->uuid, $schedule->id]), [
                'action_taken' => 'Service done',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('asset_maintenance_schedules', [
            'id'     => $schedule->id,
            'status' => MaintenanceStatus::Scheduled->value,
        ]);
    }

    /**
     * The maintenance workbench is a write-tier area, and a read-only viewer is kept out of it.
     *
     * This once asserted the opposite. Two separate things gate this screen: the Spatie permission
     * says what you may do once you are in, and the hub tier says whether you may be in at all.
     * AssetHubs puts Maintenance — the hub and its card — at WRITE deliberately, and
     * `EnsureModuleTier` exists so that "the hub role separation is real, not just visual". A
     * viewer holding `assets.maintenance.view` reads maintenance on an asset's own page; the
     * workbench is for the people who act on it.
     */
    public function test_a_read_only_viewer_is_kept_out_of_the_maintenance_workbench(): void
    {
        $this->actingAs($this->assetViewer)
            ->get(route('assets.maintenance.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_manager_reaches_the_maintenance_workbench(): void
    {
        $this->actingAs($this->assetManager)
            ->get(route('assets.maintenance.index'))
            ->assertOk();
    }
}
