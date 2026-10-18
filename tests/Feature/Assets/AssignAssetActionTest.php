<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\AssignAssetAction;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetSetting;
use App\Modules\Assets\Models\Assets;

class AssignAssetActionTest extends AssetTestCase
{
    private AssignAssetAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(AssignAssetAction::class);
    }

    // ── Happy path (no approval threshold) ───────────────────────────────────

    public function test_creates_assignment_when_below_threshold(): void
    {
        AssetSetting::set('approval_threshold_l1', 0);

        $asset = Assets::factory()->create(['purchase_cost' => 1000]);

        $result = $this->action->execute([
            'asset_id'        => $asset->id,
            'assigned_to'     => $this->assetViewer->id,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
        ], $this->assetManager->id);

        $this->assertInstanceOf(AssetAssignment::class, $result);
        $this->assertTrue($result->is_current);
        $this->assertEquals($this->assetViewer->id, $result->assigned_to);
    }

    public function test_sets_asset_status_to_active_on_assignment(): void
    {
        $asset = Assets::factory()->unallocated()->create(['purchase_cost' => 100]);

        $this->action->execute([
            'asset_id'        => $asset->id,
            'assigned_to'     => $this->assetViewer->id,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
        ], $this->assetManager->id);

        $this->assertEquals('active', $asset->fresh()->asset_status);
    }

    public function test_closes_previous_active_assignment(): void
    {
        $asset = Assets::factory()->active()->create(['purchase_cost' => 100]);

        AssetAssignment::factory()->create([
            'asset_id'    => $asset->id,
            'assigned_to' => $this->admin->id,
            'is_current'  => true,
        ]);

        $this->action->execute([
            'asset_id'        => $asset->id,
            'assigned_to'     => $this->assetViewer->id,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
        ], $this->assetManager->id);

        $stale = AssetAssignment::where('asset_id', $asset->id)
            ->where('assigned_to', $this->admin->id)
            ->first();

        $this->assertFalse($stale->is_current);
        $this->assertNotNull($stale->returned_at);
    }

    public function test_writes_custody_log(): void
    {
        $asset = Assets::factory()->create(['purchase_cost' => 100]);

        $this->action->execute([
            'asset_id'        => $asset->id,
            'assigned_to'     => $this->assetViewer->id,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
        ], $this->assetManager->id);

        $this->assertDatabaseHas('asset_custody_logs', [
            'asset_id'     => $asset->id,
            'action'       => 'assigned',
            'to_user_id'   => $this->assetViewer->id,
            'performed_by' => $this->assetManager->id,
        ]);
    }

    // ── Approval routing ─────────────────────────────────────────────────────

    public function test_creates_approval_when_asset_value_exceeds_threshold(): void
    {
        AssetSetting::set('approval_threshold_l1', 50000, 'float');
        AssetSetting::set('approval_approvers_assignment', [$this->admin->id], 'json');

        $asset = Assets::factory()->create(['purchase_cost' => 100000]);

        $result = $this->action->execute([
            'asset_id'        => $asset->id,
            'assigned_to'     => $this->assetViewer->id,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
        ], $this->assetManager->id);

        $this->assertInstanceOf(AssetApproval::class, $result);
        $this->assertEquals('pending', $result->status->value ?? $result->status);
    }

    public function test_skips_approval_when_skip_flag_set(): void
    {
        AssetSetting::set('approval_threshold_l1', 50000, 'float');

        $asset = Assets::factory()->create(['purchase_cost' => 100000]);

        $result = $this->action->execute([
            'asset_id'        => $asset->id,
            'assigned_to'     => $this->assetViewer->id,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
            '_skip_approval'  => true,
        ], $this->assetManager->id);

        $this->assertInstanceOf(AssetAssignment::class, $result);
    }

    public function test_no_approval_when_threshold_is_zero(): void
    {
        AssetSetting::set('approval_threshold_l1', 0, 'float');

        $asset = Assets::factory()->create(['purchase_cost' => 999999]);

        $result = $this->action->execute([
            'asset_id'        => $asset->id,
            'assigned_to'     => $this->assetViewer->id,
            'assignment_type' => 'permanent',
            'effective_date'  => now()->toDateString(),
        ], $this->assetManager->id);

        $this->assertInstanceOf(AssetAssignment::class, $result);
    }
}
