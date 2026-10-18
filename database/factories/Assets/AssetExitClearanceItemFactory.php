<?php

namespace Database\Factories\Assets;

use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Models\AssetExitClearanceItem;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetExitClearanceItemFactory extends Factory
{
    protected $model = AssetExitClearanceItem::class;

    public function definition(): array
    {
        return [
            'clearance_id'  => AssetExitClearance::factory(),
            'asset_id'      => Assets::factory(),
            'assignment_id' => null,
            'resolution'    => 'pending',
            'notes'         => null,
        ];
    }

    public function returned(): static
    {
        return $this->state([
            'resolution'   => 'returned',
            'resolved_at'  => now(),
        ]);
    }

    public function waived(): static
    {
        return $this->state([
            'resolution'    => 'waived',
            'waiver_reason' => 'Item irrecoverable',
            'resolved_at'   => now(),
        ]);
    }
}
