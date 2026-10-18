<?php

namespace Database\Factories\Assets;

use App\Modules\Assets\Models\AssetDepreciationEntry;
use App\Modules\Assets\Models\AssetDepreciationSchedule;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetDepreciationEntryFactory extends Factory
{
    protected $model = AssetDepreciationEntry::class;

    public function definition(): array
    {
        return [
            'schedule_id'         => AssetDepreciationSchedule::factory(),
            'asset_id'            => Assets::factory(),
            'period_year'         => (int) now()->format('Y'),
            'period_month'        => (int) now()->format('n'),
            'period_sequence'     => 1,
            'opening_book_value'  => 100000,
            'depreciation_amount' => 1500,
            'closing_book_value'  => 98500,
            'is_posted'           => false,
        ];
    }

    public function posted(): static
    {
        return $this->state([
            'is_posted' => true,
            'posted_at' => now(),
        ]);
    }
}
