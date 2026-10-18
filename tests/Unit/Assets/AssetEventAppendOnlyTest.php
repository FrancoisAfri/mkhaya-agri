<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Models\AssetPolicyEnforcementLog;
use App\Modules\Assets\Models\Assets;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Append-only audit log guard.
 *
 * The Asset module enforces immutability on policy enforcement logs (M9 audit
 * trail) by the same mechanism as custody logs. This test pins that
 * contract — once written, the log entry cannot be updated or deleted.
 */
class AssetEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_enforcement_log_cannot_be_updated(): void
    {
        $log = $this->makeLog();

        $this->expectException(\Exception::class);
        $log->update(['check_result' => 'failed']);
    }

    public function test_enforcement_log_cannot_be_deleted(): void
    {
        $log = $this->makeLog();

        $this->expectException(\Exception::class);
        $log->delete();
    }

    public function test_enforcement_log_save_with_dirty_attributes_throws(): void
    {
        $log = $this->makeLog();

        $log->action_type = 'transfer';

        $this->expectException(\Exception::class);
        $log->save();
    }

    public function test_new_log_records_can_still_be_created(): void
    {
        $log = $this->makeLog();
        $this->assertDatabaseHas('asset_policy_enforcement_logs', ['id' => $log->id]);
    }

    private function makeLog(): AssetPolicyEnforcementLog
    {
        $user  = User::factory()->create(['status' => 1]);
        $asset = Assets::factory()->create();

        return AssetPolicyEnforcementLog::create([
            'asset_id'     => $asset->id,
            'user_id'      => $user->id,
            'action_type'  => 'assignment',
            'check_result' => 'passed',
            'checked_at'   => now(),
            'context'      => ['source' => 'unit-test'],
        ]);
    }
}
