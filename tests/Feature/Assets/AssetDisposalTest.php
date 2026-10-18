<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\ApproveAssetApprovalAction;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Services\AssetPolicyEnforcementService;

/**
 * Disposal flow rules: must pass policy (no remaining book value without
 * approval) and must go through the AssetApproval chain (ApprovalType::Disposal).
 */
class AssetDisposalTest extends AssetTestCase
{
    public function test_disposal_with_book_value_blocked_without_approval(): void
    {
        $asset = Assets::factory()->create(['purchase_cost' => 50000]);

        $service = new AssetPolicyEnforcementService();
        $result = $service->checkPolicy('disposal', [
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetManager->id,
            'book_value'   => 30000,
            'has_approval' => false,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertContains('value_threshold', array_column($result['violations'], 'type'));

        $this->assertDatabaseHas('asset_policy_violations', [
            'asset_id'       => $asset->id,
            'violation_type' => 'value_threshold',
            'action_blocked' => true,
        ]);
    }

    public function test_disposal_with_zero_book_value_passes_without_approval(): void
    {
        $asset = Assets::factory()->create();

        $service = new AssetPolicyEnforcementService();
        $result = $service->checkPolicy('disposal', [
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetManager->id,
            'book_value'   => 0,
            'has_approval' => false,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertEmpty($result['violations']);
    }

    public function test_disposal_approval_requires_multi_step_chain_for_high_value(): void
    {
        $asset = Assets::factory()->create(['purchase_cost' => 250000]);

        $approval = AssetApproval::factory()->disposal()->create([
            'approvable_id' => $asset->id,
            'requested_by'  => $this->assetViewer->id,
            'total_steps'   => 3,
            'current_step'  => 1,
        ]);

        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $this->assetManager->id,
        ]);
        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 2,
            'approver_id' => $this->admin->id,
        ]);

        // After step 1 approval, chain advances but is not yet approved
        $action = new ApproveAssetApprovalAction();
        $action->execute($approval, $this->assetManager->id, true);

        $approval->refresh();
        $this->assertSame(2, $approval->current_step);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
    }

    public function test_disposal_approval_completes_only_on_final_step_approval(): void
    {
        $asset = Assets::factory()->create();

        $approval = AssetApproval::factory()->disposal()->create([
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

    public function test_disposal_request_can_be_rejected(): void
    {
        $asset = Assets::factory()->create();

        $approval = AssetApproval::factory()->disposal()->create([
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

        (new ApproveAssetApprovalAction())->execute(
            $approval,
            $this->admin->id,
            false,
            'Asset still serviceable',
        );

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
    }

    public function test_disposal_requester_cannot_approve_own_request(): void
    {
        $asset = Assets::factory()->create();

        $approval = AssetApproval::factory()->disposal()->create([
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
}
