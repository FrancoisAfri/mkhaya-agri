<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetPerformanceLog;
use App\Modules\Assets\Models\AssetUtilizationReport;
use App\Modules\Assets\Models\AssetUtilizationSnapshot;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class GenerateUtilizationReportAction
{
    public function execute(array $data, int $generatedBy): AssetUtilizationReport
    {
        return DB::transaction(function () use ($data, $generatedBy) {
            $periodStart = $data['period_start'];
            $periodEnd   = $data['period_end'];
            $scope       = $data['scope'] ?? 'all';
            $scopeId     = $data['scope_id'] ?? null;

            $periodDays  = now()->parse($periodStart)->diffInDays(now()->parse($periodEnd)) + 1;
            $periodMinutes = $periodDays * 24 * 60;

            // Resolve asset set
            $assetQuery = Assets::query()->withTrashed(false);
            if ($scope === 'category' && $scopeId) {
                $assetQuery->where('asset_category_id', $scopeId);
            } elseif ($scope === 'location' && $scopeId) {
                $assetQuery->where('location_id', $scopeId);
            } elseif ($scope === 'single' && $scopeId) {
                $assetQuery->where('id', $scopeId);
            }
            $assets = $assetQuery->get(['id', 'name', 'asset_tag']);

            // Aggregate performance logs per asset
            $logs = AssetPerformanceLog::query()
                ->whereBetween('logged_at', [$periodStart, $periodEnd . ' 23:59:59'])
                ->when($assets->isNotEmpty(), fn($q) => $q->whereIn('asset_id', $assets->pluck('id')))
                ->selectRaw('asset_id, SUM(operating_hours_delta) as total_hours, SUM(downtime_minutes) as total_downtime, COUNT(*) as entry_count')
                ->groupBy('asset_id')
                ->get()
                ->keyBy('asset_id');

            $snapshots = [];
            $utilizationRates = [];

            foreach ($assets as $asset) {
                $log = $logs->get($asset->id);
                $totalHours    = $log ? (float) $log->total_hours    : 0;
                $totalDowntime = $log ? (int)   $log->total_downtime : 0;
                $entryCount    = $log ? (int)   $log->entry_count    : 0;

                $utilizationMinutes = $totalHours * 60;
                $idleMinutes        = max(0, $periodMinutes - $utilizationMinutes - $totalDowntime);
                $utilizationRate    = min(100, $periodMinutes > 0 ? ($utilizationMinutes / $periodMinutes) * 100 : 0);

                $snapshots[] = [
                    'asset_id'              => $asset->id,
                    'total_operating_hours' => $totalHours,
                    'total_downtime_minutes' => $totalDowntime,
                    'idle_time_minutes'     => (int) $idleMinutes,
                    'utilization_rate'      => round($utilizationRate, 2),
                    'log_entry_count'       => $entryCount,
                    'period_start'          => $periodStart,
                    'period_end'            => $periodEnd,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ];

                $utilizationRates[$asset->id] = [
                    'asset_id'        => $asset->id,
                    'name'            => $asset->name,
                    'utilization_rate' => round($utilizationRate, 2),
                ];
            }

            $sorted = collect($utilizationRates)->sortByDesc('utilization_rate');

            $summaryStats = [
                'avg_utilization'    => round($sorted->avg('utilization_rate'), 2),
                'avg_operating_hours' => round(collect($snapshots)->avg('total_operating_hours'), 2),
                'avg_downtime'       => round(collect($snapshots)->avg('total_downtime_minutes'), 2),
            ];

            $report = AssetUtilizationReport::create([
                'generated_by'          => $generatedBy,
                'report_name'           => $data['report_name'],
                'period_start'          => $periodStart,
                'period_end'            => $periodEnd,
                'scope'                 => $scope,
                'scope_id'              => $scopeId,
                'total_assets_in_scope' => $assets->count(),
                'summary_stats'         => $summaryStats,
                'top_utilized'          => $sorted->take(10)->values()->toArray(),
                'least_utilized'        => $sorted->reverse()->take(10)->values()->toArray(),
                'generated_at'          => now(),
            ]);

            foreach ($snapshots as &$snap) {
                $snap['report_id'] = $report->id;
            }

            AssetUtilizationSnapshot::insert($snapshots);

            return $report;
        });
    }
}
