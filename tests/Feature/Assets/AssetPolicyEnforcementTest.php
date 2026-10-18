<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetPolicyEnforcementLog;
use App\Modules\Assets\Models\AssetPolicyException;
use App\Modules\Assets\Models\AssetPolicyViolation;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Services\AssetPolicyEnforcementService;

class AssetPolicyEnforcementTest extends AssetTestCase
{
    public function test_high_value_assignment_without_approval_is_blocked_and_logged(): void
    {
        $asset = Assets::factory()->create();

        $service = new AssetPolicyEnforcementService();
        $result  = $service->checkPolicy('assignment', [
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetViewer->id,
            'asset_value'  => 150000,
            'has_approval' => false,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertNotEmpty($result['violations']);
        $this->assertContains('missing_approval', array_column($result['violations'], 'type'));

        $this->assertDatabaseHas('asset_policy_violations', [
            'asset_id'       => $asset->id,
            'user_id'        => $this->assetViewer->id,
            'violation_type' => 'missing_approval',
            'action_blocked' => true,
        ]);

        $this->assertDatabaseHas('asset_policy_enforcement_logs', [
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetViewer->id,
            'action_type'  => 'assignment',
            'check_result' => 'failed',
        ]);
    }

    public function test_assignment_limit_violation_recorded_after_five_active_assignments(): void
    {
        $asset = Assets::factory()->create();

        // Five current assignments
        AssetAssignment::factory()->count(5)->create([
            'assigned_to' => $this->assetViewer->id,
            'is_current'  => true,
        ]);

        $service = new AssetPolicyEnforcementService();
        $result  = $service->checkPolicy('assignment', [
            'asset_id'    => $asset->id,
            'user_id'     => $this->assetViewer->id,
            'asset_value' => 1000,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertContains('assignment_limit', array_column($result['violations'], 'type'));

        $this->assertDatabaseHas('asset_policy_violations', [
            'user_id'        => $this->assetViewer->id,
            'violation_type' => 'assignment_limit',
        ]);
    }

    public function test_approved_active_exception_skips_violation_logging(): void
    {
        $asset = Assets::factory()->create();

        AssetPolicyException::create([
            'asset_id'             => $asset->id,
            'requested_by_user_id' => $this->assetViewer->id,
            'exception_reason'     => 'Executive override',
            'business_justification' => 'CEO laptop replacement',
            'requested_at'         => now()->subDays(2),
            'valid_from'           => now()->subDay()->toDateString(),
            'valid_until'          => now()->addDays(30)->toDateString(),
            'status'               => 'approved',
            'approved_by_user_id'  => $this->admin->id,
            'approved_at'          => now()->subDay(),
        ]);

        $service = new AssetPolicyEnforcementService();
        $result  = $service->checkPolicy('assignment', [
            'asset_id'    => $asset->id,
            'user_id'     => $this->assetViewer->id,
            'asset_value' => 200000, // would normally violate
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertEmpty($result['violations']);

        $this->assertDatabaseHas('asset_policy_enforcement_logs', [
            'asset_id'     => $asset->id,
            'check_result' => 'exception_granted',
        ]);
        $this->assertDatabaseMissing('asset_policy_violations', [
            'asset_id' => $asset->id,
        ]);
    }

    public function test_passing_assignment_check_logs_passed_result(): void
    {
        $asset = Assets::factory()->create();

        $service = new AssetPolicyEnforcementService();
        $result  = $service->checkPolicy('assignment', [
            'asset_id'    => $asset->id,
            'user_id'     => $this->assetViewer->id,
            'asset_value' => 1000,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertDatabaseHas('asset_policy_enforcement_logs', [
            'asset_id'     => $asset->id,
            'check_result' => 'passed',
        ]);
    }

    public function test_disposal_with_book_value_without_approval_is_blocked(): void
    {
        $asset = Assets::factory()->create();

        $service = new AssetPolicyEnforcementService();
        $result  = $service->checkPolicy('disposal', [
            'asset_id'    => $asset->id,
            'user_id'     => $this->assetManager->id,
            'book_value'  => 5000,
            'has_approval' => false,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertContains('value_threshold', array_column($result['violations'], 'type'));
    }

    public function test_enforcement_log_is_immutable(): void
    {
        $asset = Assets::factory()->create();
        $log = AssetPolicyEnforcementLog::create([
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetManager->id,
            'action_type'  => 'assignment',
            'check_result' => 'passed',
            'checked_at'   => now(),
            'context'      => ['initial' => true],
        ]);

        $this->expectException(\Exception::class);
        $log->update(['check_result' => 'failed']);
    }

    public function test_enforcement_log_cannot_be_deleted(): void
    {
        $asset = Assets::factory()->create();
        $log = AssetPolicyEnforcementLog::create([
            'asset_id'     => $asset->id,
            'user_id'      => $this->assetManager->id,
            'action_type'  => 'transfer',
            'check_result' => 'passed',
            'checked_at'   => now(),
        ]);

        $this->expectException(\Exception::class);
        $log->delete();
    }
}
