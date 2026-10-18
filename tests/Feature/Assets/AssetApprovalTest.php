<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\ApproveAssetApprovalAction;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use App\Modules\Assets\Models\Assets;

class AssetApprovalTest extends AssetTestCase
{
    public function test_approval_advances_sequentially_through_steps(): void
    {
        $approver1 = $this->assetManager;
        $approver2 = $this->admin;

        $approval = AssetApproval::factory()->create([
            'type'         => ApprovalType::Disposal->value,
            'requested_by' => $this->assetViewer->id,
            'total_steps'  => 2,
            'current_step' => 1,
            'status'       => ApprovalStatus::Pending->value,
        ]);

        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $approver1->id,
        ]);
        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 2,
            'approver_id' => $approver2->id,
        ]);

        (new ApproveAssetApprovalAction())->execute($approval, $approver1->id, true, 'OK step 1');

        $approval->refresh();
        $this->assertSame(2, $approval->current_step);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertDatabaseHas('asset_approval_steps', [
            'approval_id' => $approval->id,
            'step_number' => 1,
            'status'      => ApprovalStatus::Approved->value,
        ]);
    }

    public function test_rejection_at_any_step_terminates_chain(): void
    {
        $approver1 = $this->assetManager;
        $approver2 = $this->admin;

        $approval = AssetApproval::factory()->create([
            'type'         => ApprovalType::Transfer->value,
            'requested_by' => $this->assetViewer->id,
            'total_steps'  => 2,
            'current_step' => 1,
        ]);

        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $approver1->id,
        ]);
        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 2,
            'approver_id' => $approver2->id,
        ]);

        (new ApproveAssetApprovalAction())->execute($approval, $approver1->id, false, 'Reject');

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
        $this->assertNotNull($approval->completed_at);

        // Step 2 must still be pending — chain is terminated, not advanced
        $this->assertDatabaseHas('asset_approval_steps', [
            'approval_id' => $approval->id,
            'step_number' => 2,
            'status'      => ApprovalStatus::Pending->value,
        ]);
    }

    public function test_requester_cannot_approve_their_own_request(): void
    {
        $approval = AssetApproval::factory()->create([
            'type'         => ApprovalType::Disposal->value,
            'requested_by' => $this->assetManager->id,
            'total_steps'  => 1,
            'current_step' => 1,
        ]);

        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $this->assetManager->id, // same user — must be blocked
        ]);

        $this->expectException(\DomainException::class);

        (new ApproveAssetApprovalAction())->execute($approval, $this->assetManager->id, true);
    }

    public function test_final_step_approval_marks_approval_complete(): void
    {
        $approver = $this->admin;

        $approval = AssetApproval::factory()->create([
            'type'         => ApprovalType::Disposal->value,
            'requested_by' => $this->assetViewer->id,
            'total_steps'  => 1,
            'current_step' => 1,
        ]);

        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $approver->id,
        ]);

        (new ApproveAssetApprovalAction())->execute($approval, $approver->id, true);

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertNotNull($approval->completed_at);
    }

    public function test_approval_requires_the_designated_approver_for_current_step(): void
    {
        $designated = $this->assetManager;
        $impostor   = $this->admin;

        $approval = AssetApproval::factory()->create([
            'type'         => ApprovalType::Transfer->value,
            'requested_by' => $this->assetViewer->id,
            'total_steps'  => 1,
            'current_step' => 1,
        ]);

        AssetApprovalStep::factory()->create([
            'approval_id' => $approval->id,
            'step_number' => 1,
            'approver_id' => $designated->id,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        (new ApproveAssetApprovalAction())->execute($approval, $impostor->id, true);
    }
}
