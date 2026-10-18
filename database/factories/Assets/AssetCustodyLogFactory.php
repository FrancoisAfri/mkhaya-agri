<?php

namespace Database\Factories\Assets;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetCustodyLogFactory extends Factory
{
    protected $model = AssetCustodyLog::class;

    public function definition(): array
    {
        return [
            'asset_id'     => Assets::factory(),
            'action'       => 'registered',
            'performed_by' => User::factory(),
            'notes'        => null,
            'occurred_at'  => now(),
        ];
    }
}
