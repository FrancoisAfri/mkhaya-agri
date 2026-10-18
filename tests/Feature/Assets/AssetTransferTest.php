<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\ApproveAssetApprovalAction;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use App\Modules\Assets\Models\Assets;

/**
 * Transfer flow is enforced through the generic AssetApproval engine
 * (ApprovalType::Transfer). These tests cover the approval/4-eyes rules
 * that any transfer must honour.
 */
class AssetTransferTest extends AssetTestCase
{
    public function test_requesting_transfer_creates_pending_approval(): void
    {
        $asset = Assets::factory()->create();

        $approval = AssetApproval::factory()->transfer()->create([
            'approvable_id' => $asset->id,
            'requested_by'  => $this->assetManager->id,
            'total_steps'   => 1,
            'current_step'  => 1,
            'status'        => ApprovalStatus::Pending->value,
        ]);

        $this->assertDatabaseHas('asset_approvals', [
            'id'     => $approval->id,
            'type'   => ApprovalType::Transfer->value,
            'status' => ApprovalStatus::Pending->value,
        ]);
        $this->assertSame(ApprovalType::Transfer, $approval->type);
    }

    public function test_approving_transfer_marks_approval_complete(): void
    {
        $asset = Assets::factory()->create(['location_id' => null]);

        $approval = AssetApproval::factory()->transfer()->create([
            'approvable_id' => $asset->id,
            'requested_by'  => $this->assetViewer->id,
            'total_steps'   => 1,
            'current_step'  => 1,
        ]);
        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $this->admin->id,
        ]);

        (new ApproveAssetApprovalAction())->execute($approval, $this->admin->id, true);

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertNotNull($approval->completed_at);
    }

    public function test_requester_cannot_approve_their_own_transfer_request(): void
    {
        $asset = Assets::factory()->create();

        $approval = AssetApproval::factory()->transfer()->create([
            'approvable_id' => $asset->id,
            'requested_by'  => $this->assetManager->id,
            'total_steps'   => 1,
            'current_step'  => 1,
        ]);
        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $this->assetManager->id,
        ]);

        $this->expectException(\DomainException::class);

        (new ApproveAssetApprovalAction())->execute($approval, $this->assetManager->id, true);
    }

    public function test_transfer_requires_approval_per_policy(): void
    {
        $asset = Assets::factory()->create();

        $service = new \App\Modules\Assets\Services\AssetPolicyEnforcementService();
        $result  = $service->checkPolicy('transfer', [
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetManager->id,
            'has_approval' => false,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertContains('missing_approval', array_column($result['violations'], 'type'));
    }

    public function test_transfer_with_approval_passes_policy(): void
    {
        $asset = Assets::factory()->create();

        $service = new \App\Modules\Assets\Services\AssetPolicyEnforcementService();
        $result  = $service->checkPolicy('transfer', [
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetManager->id,
            'has_approval' => true,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertEmpty($result['violations']);
    }
}
