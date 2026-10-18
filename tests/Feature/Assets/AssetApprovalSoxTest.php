<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Models\AssetApproval;
use Illuminate\Support\Facades\Gate;

class AssetApprovalSoxTest extends AssetTestCase
{
    public function test_requester_cannot_approve_their_own_approval(): void
    {
        $approval = new AssetApproval([
            'requested_by' => $this->assetManager->id,
            'status'       => 'pending',
            'current_step' => 1,
            'total_steps'  => 2,
        ]);
        $approval->id = 1;

        $this->actingAs($this->assetManager);

        $this->assertFalse(Gate::forUser($this->assetManager)->allows('approve', $approval));
    }

    public function test_different_user_can_approve_pending_approval(): void
    {
        $approval = new AssetApproval([
            'requested_by' => $this->assetManager->id,
            'status'       => 'pending',
            'current_step' => 1,
            'total_steps'  => 2,
        ]);
        $approval->id = 1;

        $this->assertTrue(Gate::forUser($this->admin)->allows('approve', $approval));
    }

    public function test_terminal_status_blocks_approval(): void
    {
        $approval = new AssetApproval([
            'requested_by' => $this->assetManager->id,
            'status'       => 'approved',
            'current_step' => 2,
            'total_steps'  => 2,
        ]);
        $approval->id = 1;

        $this->assertFalse(Gate::forUser($this->admin)->allows('approve', $approval));
    }
}
