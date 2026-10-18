<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\AssetMisuseDetectionRule;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\Assets;

class RunMisuseDetectionAction
{
    public function __construct(private CreateMisuseFlagAction $createFlag) {}

    public function execute(?int $assetId = null): int
    {
        $rules = AssetMisuseDetectionRule::where('is_active', true)->get();
        $flagsCreated = 0;

        $assetQuery = Assets::query()->whereNotNull('id');
        if ($assetId) {
            $assetQuery->where('id', $assetId);
        }
        $assets = $assetQuery->get();

        foreach ($rules as $rule) {
            $scopedAssets = $rule->applies_to_category
                ? $assets->where('asset_category_id', $rule->applies_to_category)
                : $assets;

            foreach ($scopedAssets as $asset) {
                $flag = match ($rule->flag_type->value) {
                    'after_hours_usage'   => $this->checkAfterHours($asset, $rule->parameters),
                    'rapid_movement'      => $this->checkRapidMovement($asset, $rule->parameters),
                    'repeated_incidents'  => $this->checkRepeatedIncidents($asset, $rule->parameters),
                    default               => null,
                };

                if ($flag !== null) {
                    $result = $this->createFlag->execute(array_merge($flag, [
                        'asset_id'  => $asset->id,
                        'flag_type' => $rule->flag_type->value,
                    ]), null);

                    if ($result->wasRecentlyCreated) {
                        $flagsCreated++;
                    }
                }
            }
        }

        return $flagsCreated;
    }

    private function checkAfterHours(Assets $asset, array $params): ?array
    {
        $start = $params['work_start'] ?? '08:00';
        $end   = $params['work_end']   ?? '18:00';

        $movements = AssetMovement::where('asset_id', $asset->id)
            ->where('moved_at', '>=', now()->subDays(7))
            ->get()
            ->filter(function ($m) use ($start, $end) {
                $time = $m->moved_at->format('H:i');
                return $time < $start || $time > $end;
            });

        if ($movements->isEmpty()) {
            return null;
        }

        return [
            'severity'    => 'medium',
            'description' => "Asset moved outside working hours ({$start}–{$end}) {$movements->count()} time(s) in the last 7 days.",
            'evidence'    => ['movement_ids' => $movements->pluck('id')->toArray()],
            'detected_at' => now()->toIso8601String(),
        ];
    }

    private function checkRapidMovement(Assets $asset, array $params): ?array
    {
        $thresholdMinutes = $params['rapid_move_minutes'] ?? 30;

        $movements = AssetMovement::where('asset_id', $asset->id)
            ->orderBy('moved_at')
            ->get();

        for ($i = 1; $i < $movements->count(); $i++) {
            $diff = $movements[$i - 1]->moved_at->diffInMinutes($movements[$i]->moved_at);
            if ($diff < $thresholdMinutes) {
                return [
                    'severity'    => 'high',
                    'description' => "Asset moved twice within {$diff} minutes (threshold: {$thresholdMinutes} min).",
                    'evidence'    => ['movement_ids' => [$movements[$i - 1]->id, $movements[$i]->id]],
                    'detected_at' => now()->toIso8601String(),
                ];
            }
        }

        return null;
    }

    private function checkRepeatedIncidents(Assets $asset, array $params): ?array
    {
        $threshold = $params['incident_threshold'] ?? 3;

        $count = AssetIncident::where('asset_id', $asset->id)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->count();

        if ($count < $threshold) {
            return null;
        }

        return [
            'severity'    => 'high',
            'description' => "{$count} incidents recorded in the last 30 days (threshold: {$threshold}).",
            'evidence'    => ['incident_count' => $count],
            'detected_at' => now()->toIso8601String(),
        ];
    }
}
