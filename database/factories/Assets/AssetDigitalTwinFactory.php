<?php

namespace Database\Factories\Assets;

use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetDigitalTwinFactory extends Factory
{
    protected $model = AssetDigitalTwin::class;

    public function definition(): array
    {
        return [
            'asset_id'             => Assets::factory(),
            'condition'            => 'good',
            'current_location_id'  => null,
            'operating_hours'      => 0,
            'odometer_reading'     => 0,
            'health_score'         => 80,
            'utilization_rate'     => 50,
            'synced_at'            => now(),
        ];
    }
}
