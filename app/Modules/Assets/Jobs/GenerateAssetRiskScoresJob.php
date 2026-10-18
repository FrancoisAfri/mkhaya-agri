<?php

namespace App\Modules\Assets\Jobs;

use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Services\AssetRiskScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAssetRiskScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function handle(AssetRiskScoringService $service): void
    {
        Assets::query()
            ->whereNotIn('asset_status', ['disposed', 'sold'])
            ->chunkById(100, function ($assets) use ($service) {
                foreach ($assets as $asset) {
                    try {
                        $service->scoreFor($asset);
                    } catch (\Throwable $e) {
                        Log::warning('Asset risk score failed', [
                            'asset_id' => $asset->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
