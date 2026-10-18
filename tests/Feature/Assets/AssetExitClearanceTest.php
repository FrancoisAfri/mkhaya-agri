<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\CreateExitClearanceAction;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Models\AssetExitClearanceItem;
use App\Modules\Assets\Models\Assets;

class AssetExitClearanceTest extends AssetTestCase
{
    public function test_creating_clearance_generates_items_for_current_assignments(): void
    {
        $employee = $this->assetViewer;

        $asset1 = Assets::factory()->create();
        $asset2 = Assets::factory()->create();
        $asset3 = Assets::factory()->create();

        AssetAssignment::factory()->create([
            'asset_id'    => $asset1->id,
            'assigned_to' => $employee->id,
            'is_current'  => true,
        ]);
        AssetAssignment::factory()->create([
            'asset_id'    => $asset2->id,
            'assigned_to' => $employee->id,
            'is_current'  => true,
        ]);
        // Not current — should NOT be included
        AssetAssignment::factory()->create([
            'asset_id'    => $asset3->id,
            'assigned_to' => $employee->id,
            'is_current'  => false,
            'returned_at' => now()->subDay(),
        ]);

        $clearance = (new CreateExitClearanceAction())->execute([
            'user_id'          => $employee->id,
            'target_exit_date' => now()->addDays(7)->toDateString(),
        ], $this->admin->id);

        $this->assertInstanceOf(AssetExitClearance::class, $clearance);
        $this->assertDatabaseHas('asset_exit_clearances', [
            'id'           => $clearance->id,
            'user_id'      => $employee->id,
            'initiated_by' => $this->admin->id,
            'status'       => 'pending',
        ]);

        $this->assertSame(2, AssetExitClearanceItem::where('clearance_id', $clearance->id)->count());
        $this->assertDatabaseHas('asset_exit_clearance_items', [
            'clearance_id' => $clearance->id,
            'asset_id'     => $asset1->id,
            'resolution'   => 'pending',
        ]);
        $this->assertDatabaseHas('asset_exit_clearance_items', [
            'clearance_id' => $clearance->id,
            'asset_id'     => $asset2->id,
        ]);
        $this->assertDatabaseMissing('asset_exit_clearance_items', [
            'clearance_id' => $clearance->id,
            'asset_id'     => $asset3->id,
        ]);
    }

    public function test_creating_clearance_with_no_current_assignments_creates_empty_clearance(): void
    {
        $employee = $this->assetViewer;

        $clearance = (new CreateExitClearanceAction())->execute([
            'user_id'          => $employee->id,
            'target_exit_date' => now()->addDays(7)->toDateString(),
            'notes'            => 'No assignments',
        ], $this->admin->id);

        $this->assertDatabaseHas('asset_exit_clearances', [
            'id'      => $clearance->id,
            'user_id' => $employee->id,
            'notes'   => 'No assignments',
        ]);
        $this->assertSame(0, AssetExitClearanceItem::where('clearance_id', $clearance->id)->count());
    }

    public function test_controller_blocks_creating_duplicate_active_clearance(): void
    {
        $employee = $this->assetViewer;

        AssetExitClearance::factory()->create([
            'user_id'      => $employee->id,
            'initiated_by' => $this->admin->id,
            'status'       => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post(route('assets.exit-clearances.store'), [
                'user_id'          => $employee->id,
                'target_exit_date' => now()->addDays(14)->toDateString(),
            ])
            ->assertSessionHasErrors('user_id');
    }

    public function test_controller_validates_target_exit_date_is_future(): void
    {
        $employee = $this->assetViewer;

        $this->actingAs($this->admin)
            ->post(route('assets.exit-clearances.store'), [
                'user_id'          => $employee->id,
                'target_exit_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('target_exit_date');
    }
}
