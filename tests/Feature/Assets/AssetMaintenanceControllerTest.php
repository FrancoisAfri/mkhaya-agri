<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\MaintenanceStatus;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\Assets;

class AssetMaintenanceControllerTest extends AssetTestCase
{
    // ── store (schedule) ─────────────────────────────────────────────────────

    public function test_store_schedules_maintenance_for_asset(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.store', $asset->uuid), [
                'title'            => 'Annual Inspection',
                'maintenance_type' => 'preventive',
                'due_date'         => now()->addDays(30)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('asset_maintenance_schedules', [
            'asset_id' => $asset->id,
            'title'    => 'Annual Inspection',
        ]);
    }

    public function test_store_requires_title(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.store', $asset->uuid), [
                'maintenance_type' => 'preventive',
                'due_date'         => now()->addDays(30)->toDateString(),
            ])
            ->assertSessionHasErrors(['title']);
    }

    public function test_store_requires_due_date(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.store', $asset->uuid), [
                'title'            => 'Inspection',
                'maintenance_type' => 'preventive',
            ])
            ->assertSessionHasErrors(['due_date']);
    }

    public function test_store_validates_maintenance_type_enum(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.store', $asset->uuid), [
                'title'            => 'Inspection',
                'maintenance_type' => 'random_type',
                'due_date'         => now()->addDays(10)->toDateString(),
            ])
            ->assertSessionHasErrors(['maintenance_type']);
    }

    public function test_store_returns_404_for_unknown_asset(): void
    {
        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.store', 'bad-uuid'), [
                'title'            => 'Inspection',
                'maintenance_type' => 'preventive',
                'due_date'         => now()->addDays(10)->toDateString(),
            ])
            ->assertNotFound();
    }

    public function test_store_requires_authentication(): void
    {
        $asset = Assets::factory()->create();

        $this->post(route('assets.maintenance.store', $asset->uuid), [
            'title'            => 'Inspection',
            'maintenance_type' => 'preventive',
            'due_date'         => now()->addDays(10)->toDateString(),
        ])->assertRedirect(route('login'));
    }

    // ── complete ─────────────────────────────────────────────────────────────

    public function test_complete_marks_schedule_as_completed(): void
    {
        $asset    = Assets::factory()->create();
        $schedule = AssetMaintenanceSchedule::factory()->create([
            'asset_id' => $asset->id,
            'status'   => MaintenanceStatus::Scheduled,
        ]);

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.complete', [$asset->uuid, $schedule->id]), [
                'action_taken' => 'Replaced air filter and cleaned unit.',
                'actual_cost'  => 1500,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('asset_maintenance_schedules', [
            'id'     => $schedule->id,
            'status' => MaintenanceStatus::Completed->value,
        ]);
    }

    public function test_complete_requires_action_taken(): void
    {
        $asset    = Assets::factory()->create();
        $schedule = AssetMaintenanceSchedule::factory()->create(['asset_id' => $asset->id]);

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.complete', [$asset->uuid, $schedule->id]), [
                'actual_cost' => 500,
            ])
            ->assertSessionHasErrors(['action_taken']);
    }

    public function test_complete_returns_404_when_schedule_belongs_to_different_asset(): void
    {
        $asset        = Assets::factory()->create();
        $otherAsset   = Assets::factory()->create();
        $schedule     = AssetMaintenanceSchedule::factory()->create(['asset_id' => $otherAsset->id]);

        $this->actingAs($this->assetManager)
            ->post(route('assets.maintenance.complete', [$asset->uuid, $schedule->id]), [
                'action_taken' => 'Done.',
            ])
            ->assertNotFound();
    }
}
