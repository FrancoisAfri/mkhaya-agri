<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\RecordAssetMovementAction;
use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\Assets;

class AssetMovementTest extends AssetTestCase
{
    public function test_record_movement_updates_asset_location(): void
    {
        $from = AssetLocation::factory()->create();
        $to   = AssetLocation::factory()->create();
        $asset = Assets::factory()->create(['location_id' => $from->id]);

        (new RecordAssetMovementAction())->execute([
            'asset_id'       => $asset->id,
            'to_location_id' => $to->id,
            'reason'         => 'Office relocation',
        ], $this->assetManager->id);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'location_id' => $to->id]);
    }

    public function test_record_movement_writes_ledger_entry(): void
    {
        $to   = AssetLocation::factory()->create();
        $asset = Assets::factory()->create();

        $movement = (new RecordAssetMovementAction())->execute([
            'asset_id'       => $asset->id,
            'to_location_id' => $to->id,
            'reason'         => 'Site visit',
            'gps_lat'        => -26.2041,
            'gps_lng'        => 28.0473,
        ], $this->assetManager->id);

        $this->assertInstanceOf(AssetMovement::class, $movement);
        $this->assertDatabaseHas('asset_movements', [
            'id'             => $movement->id,
            'asset_id'       => $asset->id,
            'to_location_id' => $to->id,
            'moved_by'       => $this->assetManager->id,
            'reason'         => 'Site visit',
        ]);
    }

    public function test_record_movement_updates_digital_twin_current_location(): void
    {
        $to   = AssetLocation::factory()->create();
        $asset = Assets::factory()->create();

        (new RecordAssetMovementAction())->execute([
            'asset_id'       => $asset->id,
            'to_location_id' => $to->id,
        ], $this->assetManager->id);

        $twin = AssetDigitalTwin::where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame($to->id, (int) $twin->current_location_id);
    }

    public function test_record_movement_writes_custody_log(): void
    {
        $to   = AssetLocation::factory()->create();
        $asset = Assets::factory()->create();

        (new RecordAssetMovementAction())->execute([
            'asset_id'       => $asset->id,
            'to_location_id' => $to->id,
        ], $this->assetManager->id);

        $this->assertDatabaseHas('asset_custody_logs', [
            'asset_id'       => $asset->id,
            'action'         => 'moved',
            'to_location_id' => $to->id,
            'performed_by'   => $this->assetManager->id,
        ]);
    }

    public function test_second_movement_records_previous_location_as_from(): void
    {
        $loc1 = AssetLocation::factory()->create();
        $loc2 = AssetLocation::factory()->create();
        $asset = Assets::factory()->create();
        $action = new RecordAssetMovementAction();

        $action->execute([
            'asset_id'       => $asset->id,
            'to_location_id' => $loc1->id,
        ], $this->assetManager->id);

        $second = $action->execute([
            'asset_id'       => $asset->id,
            'to_location_id' => $loc2->id,
        ], $this->assetManager->id);

        $this->assertSame($loc1->id, (int) $second->from_location_id);
        $this->assertSame($loc2->id, (int) $second->to_location_id);
    }
}
