<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\CreateExitClearanceAction;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Models\AssetExitClearanceItem;
use App\Modules\Assets\Models\Assets;

/**
 * Integration: simulates the downstream effect of an HR EmployeeTerminated
 * event by invoking CreateExitClearanceAction directly with the terminated
 * employee's id. Asserts that the clearance items match the user's current
 * assignments at the time of termination.
 *
 * (When the HR EmployeeTerminated listener is wired, this same action is
 * invoked from the listener — so the asserted outcomes hold end-to-end.)
 */
class AssetExitClearanceFromHRTerminationTest extends AssetTestCase
{
    public function test_termination_generates_clearance_with_all_current_assignments(): void
    {
        $employee = $this->assetViewer;

        $asset1 = Assets::factory()->create(['name' => 'Laptop A']);
        $asset2 = Assets::factory()->create(['name' => 'Phone B']);
        $stale  = Assets::factory()->create(['name' => 'Old C']);

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
        // Already-returned assignment must not appear on the clearance
        AssetAssignment::factory()->create([
            'asset_id'    => $stale->id,
            'assigned_to' => $employee->id,
            'is_current'  => false,
            'returned_at' => now()->subWeek(),
        ]);

        $clearance = (new CreateExitClearanceAction())->execute([
            'user_id'          => $employee->id,
            'target_exit_date' => now()->addDays(14)->toDateString(),
            'notes'            => 'Auto-generated from HR termination',
        ], $this->admin->id);

        $this->assertInstanceOf(AssetExitClearance::class, $clearance);

        $items = AssetExitClearanceItem::where('clearance_id', $clearance->id)->get();
        $itemAssetIds = $items->pluck('asset_id')->sort()->values()->all();
        $expected = collect([$asset1->id, $asset2->id])->sort()->values()->all();

        $this->assertSame($expected, $itemAssetIds);
        $this->assertCount(2, $items);

        // Items must be created in "pending" resolution state
        foreach ($items as $item) {
            $this->assertSame('pending', $item->resolution);
        }
    }

    public function test_termination_with_no_assignments_creates_empty_clearance(): void
    {
        $employee = $this->assetViewer;

        $clearance = (new CreateExitClearanceAction())->execute([
            'user_id'          => $employee->id,
            'target_exit_date' => now()->addDays(7)->toDateString(),
        ], $this->admin->id);

        $this->assertSame(0, $clearance->items()->count());
        $this->assertDatabaseHas('asset_exit_clearances', [
            'id'      => $clearance->id,
            'user_id' => $employee->id,
            'status'  => 'pending',
        ]);
    }

    public function test_clearance_initiator_recorded_as_actor(): void
    {
        $employee = $this->assetViewer;
        $hrUser   = $this->admin;

        $clearance = (new CreateExitClearanceAction())->execute([
            'user_id'          => $employee->id,
            'target_exit_date' => now()->addDays(7)->toDateString(),
        ], $hrUser->id);

        $this->assertSame($hrUser->id, (int) $clearance->initiated_by);
    }
}
