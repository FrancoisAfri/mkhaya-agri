<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Models\Assets;

class AssetPolicyAuthorizationTest extends AssetTestCase
{
    public function test_manager_can_update_active_asset(): void
    {
        $asset = Assets::factory()->create(['asset_status' => 'active']);

        $this->assertTrue($this->assetManager->can('update', $asset));
    }

    public function test_nobody_can_update_disposed_asset(): void
    {
        $asset = Assets::factory()->create(['asset_status' => 'disposed']);

        $this->assertFalse($this->assetManager->can('update', $asset));
        $this->assertFalse($this->admin->can('update', $asset));
    }

    public function test_viewer_cannot_create_asset(): void
    {
        $this->assertFalse($this->assetViewer->can('create', Assets::class));
    }

    public function test_manager_can_transfer_non_disposed_asset(): void
    {
        $asset = Assets::factory()->create(['asset_status' => 'active']);

        // Manager has assets.assign etc. — need assets.transfer permission
        $this->assetManager->givePermissionTo('assets.transfer');

        $this->assertTrue($this->assetManager->can('transfer', $asset));
    }

    public function test_dispose_blocked_when_already_disposed(): void
    {
        $asset = Assets::factory()->create(['asset_status' => 'disposed']);

        $this->assetManager->givePermissionTo('assets.dispose');

        $this->assertFalse($this->assetManager->can('dispose', $asset));
    }
}
