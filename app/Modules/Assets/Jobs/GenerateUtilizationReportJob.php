<?php

namespace App\Modules\Assets\Jobs;

use App\Modules\Assets\Models\AssetProductivityScore;
use App\Modules\Assets\Models\AssetUtilizationLog;
use App\Modules\Assets\Models\Assets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateUtilizationReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public function handle(): void
    {
        $periodEnd   = now()->startOfMonth()->subDay();
        $periodStart = $periodEnd->copy()->startOfMonth();

        Assets::query()
            ->whereNotIn('asset_status', ['disposed', 'sold'])
            ->chunkById(200, function ($assets) use ($periodStart, $periodEnd) {
                foreach ($assets as $asset) {
                    try {
                        $stats = AssetUtilizationLog::where('asset_id', $asset->id)
                            ->whereBetween('log_date', [$periodStart, $periodEnd])
                            ->selectRaw('SUM(hours_used) as hours_used, SUM(idle_hours) as idle_hours, AVG(utilization_rate) as avg_rate')
                            ->first();

                        if (! $stats || ! $stats->hours_used) {
                            continue;
                        }

                        AssetProductivityScore::updateOrCreate(
                            [
                                'asset_id'     => $asset->id,
                                'period_start' => $periodStart->toDateString(),
                                'period_end'   => $periodEnd->toDateString(),
                            ],
                            [
                                'uuid'              => (string) Str::uuid(),
                                'utilization_rate'  => round((float) $stats->avg_rate, 2),
                                'total_hours_used'  => (float) $stats->hours_used,
                                'total_idle_hours'  => (float) $stats->idle_hours,
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Utilization report row failed', [
                            'asset_id' => $asset->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
