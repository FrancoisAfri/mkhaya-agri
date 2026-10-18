<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetFailurePrediction;
use App\Modules\Assets\Models\AssetReplacementPrediction;
use App\Modules\Assets\Models\Assets;

class GenerateFailurePredictionAction
{
    public function execute(Assets $asset): array
    {
        $asset->loadMissing(['category']);
        $twin = AssetDigitalTwin::where('asset_id', $asset->id)->first();

        $predictions = [];
        $predictions = array_merge($predictions, $this->checkAge($asset, $twin));
        $predictions = array_merge($predictions, $this->checkMaintenance($asset, $twin));
        $predictions = array_merge($predictions, $this->checkIncidentFrequency($asset, $twin));

        $created = [];
        foreach ($predictions as $pred) {
            $created[] = AssetFailurePrediction::create(array_merge($pred, [
                'asset_id'        => $asset->id,
                'digital_twin_id' => $twin?->id,
                'prediction_source' => 'rule_engine',
                'is_actioned'     => false,
                'generated_at'    => now(),
            ]));
        }

        $this->maybeGenerateReplacementPrediction($asset, $twin);

        return $created;
    }

    private function checkAge(Assets $asset, ?AssetDigitalTwin $twin): array
    {
        $usefulLife = $asset->category?->default_useful_life_years ?? 5;
        if (! $asset->purchase_date) {
            return [];
        }

        $ageYears = now()->diffInYears($asset->purchase_date);
        if ($ageYears < $usefulLife * 0.8) {
            return [];
        }

        $confidence = min(95, (($ageYears / $usefulLife) - 0.8) * 100 + 40);

        return [[
            'failure_component'   => 'General — End of Life',
            'failure_description' => "Asset is {$ageYears} years old against a {$usefulLife}-year useful life. Increased failure risk.",
            'confidence_score'    => round($confidence, 1),
            'predicted_failure_date' => now()->addMonths((int) max(1, ($usefulLife - $ageYears) * 12))->toDateString(),
            'recommended_action'  => 'Schedule full inspection and assess replacement viability.',
            'estimated_repair_cost' => null,
        ]];
    }

    private function checkMaintenance(Assets $asset, ?AssetDigitalTwin $twin): array
    {
        if (! $twin || ! $twin->last_maintenance_at) {
            return [[
                'failure_component'   => 'Maintenance — No History',
                'failure_description' => 'No maintenance has been recorded for this asset.',
                'confidence_score'    => 60,
                'predicted_failure_date' => now()->addMonths(6)->toDateString(),
                'recommended_action'  => 'Schedule preventive maintenance immediately.',
                'estimated_repair_cost' => null,
            ]];
        }

        $daysSince = now()->diffInDays($twin->last_maintenance_at);
        if ($daysSince < 180) {
            return [];
        }

        $confidence = min(90, ($daysSince / 365) * 50 + 40);

        return [[
            'failure_component'   => 'Maintenance Overdue',
            'failure_description' => "Last maintenance was {$daysSince} days ago. Overdue.",
            'confidence_score'    => round($confidence, 1),
            'predicted_failure_date' => now()->addMonths(3)->toDateString(),
            'recommended_action'  => 'Schedule maintenance within the next 30 days.',
            'estimated_repair_cost' => null,
        ]];
    }

    private function checkIncidentFrequency(Assets $asset, ?AssetDigitalTwin $twin): array
    {
        $recentIncidents = \App\Modules\Assets\Models\AssetIncident::where('asset_id', $asset->id)
            ->where('occurred_at', '>=', now()->subMonths(6))
            ->count();

        if ($recentIncidents < 2) {
            return [];
        }

        return [[
            'failure_component'   => 'Recurring Incidents',
            'failure_description' => "{$recentIncidents} incidents in the last 6 months indicate systemic failure.",
            'confidence_score'    => min(95, $recentIncidents * 20),
            'predicted_failure_date' => now()->addMonths(2)->toDateString(),
            'recommended_action'  => 'Investigate root cause. Consider early replacement.',
            'estimated_repair_cost' => null,
        ]];
    }

    private function maybeGenerateReplacementPrediction(Assets $asset, ?AssetDigitalTwin $twin): void
    {
        $usefulLife = $asset->category?->default_useful_life_years ?? 5;
        if (! $asset->purchase_date) {
            return;
        }

        $ageYears = now()->diffInYears($asset->purchase_date);
        if ($ageYears < $usefulLife * 0.7) {
            return;
        }

        AssetReplacementPrediction::firstOrCreate(
            ['asset_id' => $asset->id, 'is_approved' => false],
            [
                'digital_twin_id'            => $twin?->id,
                'recommended_replacement_date' => now()->addMonths((int) max(1, ($usefulLife - $ageYears) * 12))->toDateString(),
                'replacement_reason'          => 'Asset approaching end of useful life based on age analysis.',
                'current_book_value'          => $asset->purchase_cost
                    ? $asset->purchase_cost * max(0, 1 - ($ageYears / $usefulLife))
                    : null,
                'confidence_score'            => min(90, ($ageYears / $usefulLife) * 80),
                'generated_at'               => now(),
            ]
        );
    }
}
