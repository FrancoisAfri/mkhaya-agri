<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\AssetBehaviorLog;
use App\Modules\Assets\Models\AssetMisuseEvent;
use Illuminate\Support\Facades\DB;

/**
 * M6 — Misuse Detection Service
 * 
 * Analyzes behavior patterns to detect anomalies.
 * Rules-based + statistical anomaly detection.
 */
class AssetMisuseDetectionService
{
    public function analyzeBehavior(int $assetId): array
    {
        $detectedEvents = [];

        // Rule 1: After-hours usage
        $afterHoursEvents = $this->detectAfterHoursUsage($assetId);
        if ($afterHoursEvents) {
            $detectedEvents[] = $afterHoursEvents;
        }

        // Rule 2: Location anomalies
        $locationAnomalies = $this->detectLocationAnomalies($assetId);
        if ($locationAnomalies) {
            $detectedEvents[] = $locationAnomalies;
        }

        // Rule 3: Excessive usage
        $excessiveUsage = $this->detectExcessiveUsage($assetId);
        if ($excessiveUsage) {
            $detectedEvents[] = $excessiveUsage;
        }

        return $detectedEvents;
    }

    private function detectAfterHoursUsage(int $assetId): ?array
    {
        $afterHoursLogs = AssetBehaviorLog::where('asset_id', $assetId)
            ->where('action_timestamp', '>=', now()->subDays(30))
            ->whereRaw('HOUR(action_timestamp) NOT BETWEEN 6 AND 18')
            ->count();

        if ($afterHoursLogs > 5) {
            return [
                'event_type'       => 'after_hours_usage',
                'severity'         => $afterHoursLogs > 15 ? 'high' : 'medium',
                'description'      => "Asset accessed {$afterHoursLogs} times outside business hours (6am-6pm) in last 30 days",
                'confidence_score' => min(100, $afterHoursLogs * 5),
                'evidence'         => ['after_hours_count' => $afterHoursLogs],
            ];
        }

        return null;
    }

    private function detectLocationAnomalies(int $assetId): ?array
    {
        // Check for rapid location changes
        $recentMovements = DB::table('asset_movements')
            ->where('asset_id', $assetId)
            ->where('movement_date', '>=', now()->subDays(7))
            ->count();

        if ($recentMovements > 10) {
            return [
                'event_type'       => 'location_anomaly',
                'severity'         => 'high',
                'description'      => "Asset moved {$recentMovements} times in 7 days — potential unauthorized transfers",
                'confidence_score' => min(100, $recentMovements * 8),
                'evidence'         => ['movement_count_7d' => $recentMovements],
            ];
        }

        return null;
    }

    private function detectExcessiveUsage(int $assetId): ?array
    {
        $usageLogs = AssetBehaviorLog::where('asset_id', $assetId)
            ->where('action_type', 'usage')
            ->where('action_timestamp', '>=', now()->subDays(30))
            ->sum('duration_minutes');

        // Threshold: 12 hours/day average = 360 hours/month
        if ($usageLogs > 360 * 60) {
            return [
                'event_type'       => 'excessive_usage',
                'severity'         => 'medium',
                'description'      => "Asset usage exceeds 12 hours/day average over 30 days",
                'confidence_score' => 70,
                'evidence'         => ['total_minutes_30d' => $usageLogs],
            ];
        }

        return null;
    }

    public function flagMisuseEvent(int $assetId, int $custodianUserId, array $eventData): AssetMisuseEvent
    {
        return AssetMisuseEvent::create([
            'asset_id'           => $assetId,
            'custodian_user_id'  => $custodianUserId,
            'event_type'         => $eventData['event_type'],
            'severity'           => $eventData['severity'],
            'detected_at'        => now(),
            'description'        => $eventData['description'],
            'evidence'           => $eventData['evidence'] ?? [],
            'confidence_score'   => $eventData['confidence_score'],
            'status'             => 'flagged',
        ]);
    }
}
