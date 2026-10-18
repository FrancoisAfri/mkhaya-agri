<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\LogAssetIncidentAction;
use App\Modules\Assets\Actions\ResolveIncidentAction;
use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\Assets;

class AssetIncidentTest extends AssetTestCase
{
    public function test_logging_incident_creates_record_and_custody_log(): void
    {
        $asset = Assets::factory()->create();

        $incident = (new LogAssetIncidentAction())->execute([
            'asset_id'    => $asset->id,
            'title'       => 'Screen cracked',
            'description' => 'Dropped during transport',
            'severity'    => 'high',
            'repair_cost' => 1500,
        ], $this->assetManager->id);

        $this->assertInstanceOf(AssetIncident::class, $incident);
        $this->assertDatabaseHas('asset_incidents', [
            'asset_id'    => $asset->id,
            'title'       => 'Screen cracked',
            'severity'    => 'high',
            'reported_by' => $this->assetManager->id,
            'is_resolved' => false,
        ]);

        $this->assertDatabaseHas('asset_custody_logs', [
            'asset_id' => $asset->id,
            'action'   => 'incident_reported',
        ]);
    }

    public function test_logging_incident_creates_digital_twin_if_missing(): void
    {
        $asset = Assets::factory()->create();

        $this->assertDatabaseMissing('asset_digital_twins', ['asset_id' => $asset->id]);

        (new LogAssetIncidentAction())->execute([
            'asset_id' => $asset->id,
            'title'    => 'Battery fault',
            'severity' => 'medium',
        ], $this->assetManager->id);

        $this->assertDatabaseHas('asset_digital_twins', ['asset_id' => $asset->id]);

        $twin = AssetDigitalTwin::where('asset_id', $asset->id)->first();
        $this->assertDatabaseHas('asset_incidents', [
            'asset_id'        => $asset->id,
            'digital_twin_id' => $twin->id,
        ]);
    }

    public function test_resolving_incident_writes_resolution_and_log(): void
    {
        $asset    = Assets::factory()->create();
        $incident = (new LogAssetIncidentAction())->execute([
            'asset_id' => $asset->id,
            'title'    => 'Overheating',
            'severity' => 'high',
        ], $this->assetManager->id);

        (new ResolveIncidentAction())->execute($incident, [
            'resolution_notes' => 'Replaced fan',
            'repair_cost'      => 850.50,
        ], $this->admin->id);

        $this->assertDatabaseHas('asset_incidents', [
            'id'               => $incident->id,
            'is_resolved'      => true,
            'resolution_notes' => 'Replaced fan',
            'repair_cost'      => 850.50,
        ]);

        $this->assertDatabaseHas('asset_custody_logs', [
            'asset_id' => $asset->id,
            'action'   => 'incident_resolved',
        ]);
    }

    public function test_report_incident_endpoint_records_incident_for_authenticated_manager(): void
    {
        $asset = Assets::factory()->create();

        $response = $this->actingAs($this->assetManager)
            ->post(route('assets.incidents.store', $asset->uuid), [
                'title'       => 'Water damage',
                'severity'    => 'critical',
                'description' => 'Spilled coffee on keyboard',
                'occurred_at' => now()->toDateTimeString(),
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('asset_incidents', [
            'asset_id'    => $asset->id,
            'title'       => 'Water damage',
            'severity'    => 'critical',
            'reported_by' => $this->assetManager->id,
        ]);
    }

    public function test_condition_after_updates_asset_and_twin_condition(): void
    {
        $asset = Assets::factory()->create(['condition' => 'good']);

        (new LogAssetIncidentAction())->execute([
            'asset_id'        => $asset->id,
            'title'           => 'Crash',
            'severity'        => 'critical',
            'condition_after' => 'poor',
        ], $this->assetManager->id);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'condition' => 'poor']);
        $this->assertDatabaseHas('asset_digital_twins', ['asset_id' => $asset->id, 'condition' => 'poor']);
    }
}
