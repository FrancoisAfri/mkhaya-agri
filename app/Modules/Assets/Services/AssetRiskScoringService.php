<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetRiskScore;
use App\Modules\Assets\Models\Assets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class AssetRiskScoringService
{
    public function scoreFor(Assets $asset, ?CarbonImmutable $asOf = null): AssetRiskScore
    {
        $asOf = $asOf ?? CarbonImmutable::now();

        $ageRisk         = $this->ageRisk($asset, $asOf);
        $conditionRisk   = $this->conditionRisk($asset);
        $incidentRisk    = $this->incidentRisk($asset, $asOf);
        $maintenanceRisk = $this->maintenanceRisk($asset, $asOf);
        $utilizationRisk = $this->utilizationRisk($asset, $asOf);

        $composite = (int) round(
            ($ageRisk * 0.20)
            + ($conditionRisk * 0.25)
            + ($incidentRisk * 0.20)
            + ($maintenanceRisk * 0.20)
            + ($utilizationRisk * 0.15)
        );

        $level = match (true) {
            $composite >= 80 => 'critical',
            $composite >= 60 => 'high',
            $composite >= 35 => 'medium',
            default          => 'low',
        };

        $anomalies = array_filter([
            $ageRisk >= 70         ? 'Asset is past 80% of useful life'         : null,
            $conditionRisk >= 70   ? 'Condition recorded as poor or worse'      : null,
            $incidentRisk >= 60    ? 'Multiple incidents within last 90 days'   : null,
            $maintenanceRisk >= 70 ? 'Maintenance overdue or skipped'           : null,
            $utilizationRisk >= 70 ? 'Asset has been idle for 30+ days'         : null,
        ]);

        $recommendations = $this->recommendations($composite, $ageRisk, $conditionRisk, $incidentRisk, $maintenanceRisk, $utilizationRisk);

        return AssetRiskScore::updateOrCreate(
            ['asset_id' => $asset->id, 'score_date' => $asOf->toDateString()],
            [
                'uuid'               => (string) Str::uuid(),
                'risk_score'         => $composite,
                'risk_level'         => $level,
                'maintenance_score'  => $maintenanceRisk,
                'incident_score'     => $incidentRisk,
                'utilization_score'  => $utilizationRisk,
                'compliance_score'   => 100 - $maintenanceRisk,
                'misuse_score'       => 0,
                'anomalies_detected' => array_values($anomalies),
                'recommendations'    => $recommendations,
            ]
        );
    }

    private function ageRisk(Assets $asset, CarbonImmutable $asOf): int
    {
        if (! $asset->purchase_date) {
            return 0;
        }

        $usefulLife = (int) ($asset->category?->default_useful_life_years ?? 7);
        if ($usefulLife <= 0) {
            return 0;
        }

        $ageYears = $asOf->diffInYears($asset->purchase_date);
        $ratio = $ageYears / $usefulLife;

        return (int) round(min(100, max(0, $ratio * 100)));
    }

    private function conditionRisk(Assets $asset): int
    {
        return match ($asset->condition) {
            'irreparable'    => 100,
            'damaged'        => 85,
            'poor'           => 70,
            'fair'           => 40,
            'good'           => 15,
            'new'            => 0,
            default          => 30,
        };
    }

    private function incidentRisk(Assets $asset, CarbonImmutable $asOf): int
    {
        $count = AssetIncident::where('asset_id', $asset->id)
            ->where('occurred_at', '>=', $asOf->subDays(90))
            ->count();

        return (int) min(100, $count * 20);
    }

    private function maintenanceRisk(Assets $asset, CarbonImmutable $asOf): int
    {
        $overdue = AssetMaintenanceSchedule::where('asset_id', $asset->id)
            ->where('status', 'scheduled')
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->count();

        if ($overdue === 0) {
            return 10;
        }

        return (int) min(100, 40 + ($overdue * 20));
    }

    private function utilizationRisk(Assets $asset, CarbonImmutable $asOf): int
    {
        $lastMovement = AssetMovement::where('asset_id', $asset->id)
            ->orderByDesc('moved_at')
            ->value('moved_at');

        if (! $lastMovement) {
            return 30;
        }

        $idleDays = $asOf->diffInDays($lastMovement);

        return match (true) {
            $idleDays >= 90 => 90,
            $idleDays >= 60 => 70,
            $idleDays >= 30 => 50,
            default         => 10,
        };
    }

    private function recommendations(int $composite, int $age, int $cond, int $inc, int $maint, int $util): array
    {
        $out = [];

        if ($composite >= 80) {
            $out[] = 'Schedule immediate inspection and review for replacement';
        }
        if ($maint >= 70) {
            $out[] = 'Perform overdue maintenance before next use';
        }
        if ($cond >= 70) {
            $out[] = 'Condition is critical — withdraw from active use until assessed';
        }
        if ($inc >= 60) {
            $out[] = 'Investigate recurring incidents for root cause';
        }
        if ($age >= 80) {
            $out[] = 'Evaluate replacement — asset near or past useful life';
        }
        if ($util >= 70) {
            $out[] = 'Asset idle — consider reassignment or disposal';
        }

        return $out;
    }
}
