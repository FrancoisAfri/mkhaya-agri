<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\Assets;
use Database\Factories\Assets\AssetSettingFactory;

class AssetAssignmentControllerTest extends AssetTestCase
{
    // ── assign ───────────────────────────────────────────────────────────────

    public function test_assign_creates_assignment_for_unallocated_asset(): void
    {
        $asset = Assets::factory()->unallocated()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.assign', $asset->uuid), [
                'assigned_to'     => $this->assetViewer->id,
                'assignment_type' => 'permanent',
                'effective_date'  => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('asset_assignments', [
            'asset_id'    => $asset->id,
            'assigned_to' => $this->assetViewer->id,
            'is_current'  => true,
        ]);
    }

    public function test_assign_closes_previous_active_assignment(): void
    {
        $asset      = Assets::factory()->active()->create();
        $oldHolder  = $this->assetManager;
        $newHolder  = $this->assetViewer;

        AssetAssignment::factory()->create([
            'asset_id'    => $asset->id,
            'assigned_to' => $oldHolder->id,
            'is_current'  => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('assets.assign', $asset->uuid), [
                'assigned_to'     => $newHolder->id,
                'assignment_type' => 'permanent',
                'effective_date'  => now()->toDateString(),
            ]);

        $this->assertDatabaseHas('asset_assignments', [
            'asset_id'    => $asset->id,
            'assigned_to' => $oldHolder->id,
            'is_current'  => false,
        ]);

        $this->assertDatabaseHas('asset_assignments', [
            'asset_id'    => $asset->id,
            'assigned_to' => $newHolder->id,
            'is_current'  => true,
        ]);
    }

    public function test_assign_requires_assigned_to(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.assign', $asset->uuid), [
                'assignment_type' => 'permanent',
                'effective_date'  => now()->toDateString(),
            ])
            ->assertSessionHasErrors(['assigned_to']);
    }

    public function test_assign_requires_effective_date(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.assign', $asset->uuid), [
                'assigned_to'     => $this->assetViewer->id,
                'assignment_type' => 'permanent',
            ])
            ->assertSessionHasErrors(['effective_date']);
    }

    public function test_assign_validates_return_date_after_effective_date(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->post(route('assets.assign', $asset->uuid), [
                'assigned_to'     => $this->assetViewer->id,
                'assignment_type' => 'temporary',
                'effective_date'  => '2026-05-13',
                'return_due_date' => '2026-05-01', // before effective
            ])
            ->assertSessionHasErrors(['return_due_date']);
    }

    public function test_assign_returns_404_for_unknown_asset(): void
    {
        $this->actingAs($this->assetManager)
            ->post(route('assets.assign', 'bad-uuid'), [
                'assigned_to'     => $this->assetViewer->id,
                'assignment_type' => 'permanent',
                'effective_date'  => now()->toDateString(),
            ])
            ->assertNotFound();
    }

    public function test_assign_requires_authentication(): void
    {
        $asset = Assets::factory()->create();

        $this->post(route('assets.assign', $asset->uuid), [
            'assigned_to'     => 1,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
        ])
        ->assertRedirect(route('login'));
    }

    // ── return ───────────────────────────────────────────────────────────────

    public function test_return_marks_assignment_inactive_and_asset_unallocated(): void
    {
        $asset      = Assets::factory()->active()->create();
        $assignment = AssetAssignment::factory()->create([
            'asset_id'    => $asset->id,
            'assigned_to' => $this->assetViewer->id,
            'is_current'  => true,
        ]);

        $this->actingAs($this->assetManager)
            ->post(route('assets.assignment.return', [$asset->uuid, $assignment->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('asset_assignments', [
            'id'         => $assignment->id,
            'is_current' => false,
        ]);

        $this->assertDatabaseHas('assets', [
            'id'          => $asset->id,
            'asset_status' => 'unallocated',
        ]);
    }

    public function test_return_writes_custody_log_entry(): void
    {
        $asset      = Assets::factory()->active()->create();
        $assignment = AssetAssignment::factory()->create([
            'asset_id'   => $asset->id,
            'is_current' => true,
        ]);

        $this->actingAs($this->assetManager)
            ->post(route('assets.assignment.return', [$asset->uuid, $assignment->id]));

        $this->assertDatabaseHas('asset_custody_logs', [
            'asset_id' => $asset->id,
            'action'   => 'returned',
        ]);
    }

    public function test_return_returns_404_when_assignment_belongs_to_different_asset(): void
    {
        $asset       = Assets::factory()->create();
        $otherAsset  = Assets::factory()->create();
        $assignment  = AssetAssignment::factory()->create(['asset_id' => $otherAsset->id]);

        $this->actingAs($this->assetManager)
            ->post(route('assets.assignment.return', [$asset->uuid, $assignment->id]))
            ->assertNotFound();
    }
}
