<?php

namespace Database\Factories\Assets;

use App\Modules\Assets\Models\AssetSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetSettingFactory extends Factory
{
    protected $model = AssetSetting::class;

    public function definition(): array
    {
        return [
            'key'   => fake()->unique()->word(),
            'value' => '0',
            'cast'  => 'string',
        ];
    }

    public static function seedDefaults(): void
    {
        AssetSetting::firstOrCreate(['key' => 'asset_number_prefix'],  ['value' => 'AST', 'cast' => 'string']);
        AssetSetting::firstOrCreate(['key' => 'asset_number_sequence'], ['value' => '0',   'cast' => 'integer']);
        AssetSetting::firstOrCreate(['key' => 'approval_threshold_l1'], ['value' => '0',   'cast' => 'float']);
    }
}
