<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\Assets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetCustodyLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_custody_log_cannot_be_updated(): void
    {
        $asset = Assets::factory()->create();
        $log = AssetCustodyLog::create([
            'asset_id'     => $asset->id,
            'action'       => 'registered',
            'performed_by' => $asset->user_id,
            'notes'        => 'Initial registration',
            'occurred_at'  => now(),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $log->update(['notes' => 'Attempted tampering']);
    }

    public function test_custody_log_cannot_be_deleted(): void
    {
        $asset = Assets::factory()->create();
        $log = AssetCustodyLog::create([
            'asset_id'     => $asset->id,
            'action'       => 'assigned',
            'performed_by' => $asset->user_id,
            'occurred_at'  => now(),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $log->delete();
    }

    public function test_custody_log_save_after_dirty_throws(): void
    {
        $asset = Assets::factory()->create();
        $log = AssetCustodyLog::create([
            'asset_id'     => $asset->id,
            'action'       => 'moved',
            'performed_by' => $asset->user_id,
            'occurred_at'  => now(),
        ]);

        $log->notes = 'Mutate';

        $this->expectException(\LogicException::class);
        $log->save();
    }

    public function test_creating_new_custody_logs_is_still_allowed(): void
    {
        $asset = Assets::factory()->create();

        $log = AssetCustodyLog::create([
            'asset_id'     => $asset->id,
            'action'       => 'registered',
            'performed_by' => $asset->user_id,
            'occurred_at'  => now(),
        ]);

        $this->assertDatabaseHas('asset_custody_logs', ['id' => $log->id]);
    }
}
