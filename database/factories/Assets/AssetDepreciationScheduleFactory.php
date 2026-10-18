<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\DepreciationMethod;
use App\Modules\Assets\Models\AssetDepreciationSchedule;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetDepreciationScheduleFactory extends Factory
{
    protected $model = AssetDepreciationSchedule::class;

    public function definition(): array
    {
        return [
            'asset_id'            => Assets::factory(),
            'depreciation_method' => DepreciationMethod::StraightLine->value,
            'useful_life_years'   => 5,
            'sars_wear_rate'      => null,
            'purchase_cost'       => 100000,
            'salvage_value'       => 10000,
            'total_periods'       => 60,
            'generated_by'        => User::factory(),
            'generated_at'        => now(),
            'is_active'           => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
