<?php

namespace Database\Factories\Assets;

use App\Modules\Assets\Models\AssetLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetLocationFactory extends Factory
{
    protected $model = AssetLocation::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->unique()->city(),
            'code'      => strtoupper(fake()->unique()->bothify('LOC-###')),
            'parent_id' => null,
            'address'   => fake()->address(),
            'gps_lat'   => null,
            'gps_lng'   => null,
            'status'    => 1,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => 0]);
    }
}
