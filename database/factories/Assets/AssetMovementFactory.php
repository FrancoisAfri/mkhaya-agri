<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetMovementFactory extends Factory
{
    protected $model = AssetMovement::class;

    public function definition(): array
    {
        $asset = Assets::factory()->create();
        $twin  = AssetDigitalTwin::factory()->create(['asset_id' => $asset->id]);

        return [
            'asset_id'         => $asset->id,
            'digital_twin_id'  => $twin->id,
            'from_location_id' => null,
            'to_location_id'   => null,
            'moved_by'         => User::factory(),
            'reason'           => fake()->sentence(),
            'gps_lat'          => null,
            'gps_lng'          => null,
            'moved_at'         => now(),
        ];
    }
}
