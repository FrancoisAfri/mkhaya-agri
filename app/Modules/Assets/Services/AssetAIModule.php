<?php

namespace App\Modules\Assets\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetFailurePrediction;
use App\Modules\Assets\Models\AssetReplacementPrediction;
use App\Modules\Assets\Models\Assets;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AssetAIModule
{
    public function __construct(private AgentRegistry $registry) {}

    public function register(): array
    {
        return [
            'module_name'  => 'assets',
            'capabilities' => [
                'failure_prediction',
                'replacement_recommendation',
                'risk_assessment',
                'maintenance_optimization',
                'lifecycle_insights',
                'dashboard_summary',
                'utilization_analysis',
            ],
            'tools' => [
                ['name' => 'get_asset_health_summary',  'handler' => [$this, 'getAssetHealthSummary']],
                ['name' => 'predict_asset_failure',     'handler' => [$this, 'predictAssetFailure']],
                ['name' => 'recommend_replacement',     'handler' => [$this, 'recommendReplacement']],
                ['name' => 'analyze_asset_risk',        'handler' => [$this, 'analyzeAssetRisk']],
                ['name' => 'summarize_asset_risk',      'handler' => [$this, 'summarizeAssetRisk']],
                ['name' => 'recommend_maintenance',     'handler' => [$this, 'recommendMaintenanceSchedule']],
                ['name' => 'summarize_lifecycle',       'handler' => [$this, 'summarizeLifecycle']],
                ['name' => 'analyze_utilization',       'handler' => [$this, 'analyzeUtilization']],
                ['name' => 'summarize_dashboard',       'handler' => [$this, 'summarizeDashboard']],
            ],
        ];
    }

    public function summarizeAssetRisk(Assets $asset): array
    {
        return Cache::remember("asset_ai.risk.{$asset->id}", 3600, function () use ($asset) {
            $context = [
                'asset' => [
                    'name'           => $asset->name,
                    'asset_number'   => $asset->asset_number,
                    'condition'      => $asset->condition,
                    'status'         => $asset->asset_status,
                    'purchase_date'  => $asset->purchase_date?->toDateString(),
                    'purchase_cost'  => $asset->purchase_cost,
                ],
                'maintenance_history' => $asset->maintenanceSchedules()
                    ->orderByDesc('due_date')->limit(10)->get(['status', 'due_date', 'completed_at', 'actual_cost'])->toArray(),
                'incidents_last_90_days' => $asset->incidents()
                    ->where('occurred_at', '>=', now()->subDays(90))
                    ->get(['incident_type', 'severity', 'occurred_at', 'repair_cost'])->toArray(),
                'risk_profile' => $asset->riskProfile?->only(['risk_score', 'risk_level']),
            ];

            return $this->ask(
                prompt: 'Assess the operational risk for this asset. Identify the top 3 risk drivers and suggest concrete actions.',
                context: $context,
                system: 'You are an enterprise asset management risk analyst. Be specific, concise, and reference data points provided.',
            );
        });
    }

    public function recommendMaintenanceSchedule(Assets $asset): array
    {
        return Cache::remember("asset_ai.maintenance.{$asset->id}", 3600, function () use ($asset) {
            $context = [
                'asset_category'      => $asset->category?->name,
                'age_years'           => $asset->purchase_date?->diffInYears(now()),
                'condition'           => $asset->condition,
                'last_5_maintenances' => $asset->maintenanceSchedules()
                    ->orderByDesc('due_date')->limit(5)
                    ->get(['type', 'status', 'due_date', 'completed_at'])->toArray(),
                'failure_predictions' => AssetFailurePrediction::where('asset_id', $asset->id)
                    ->latest('prediction_date')->limit(2)
                    ->get(['predicted_failure_date', 'failure_type', 'confidence_score'])->toArray(),
            ];

            return $this->ask(
                prompt: 'Recommend an optimal maintenance schedule for the next 12 months. List interval, type, and rationale.',
                context: $context,
                system: 'You are a predictive maintenance specialist. Output recommendations as a numbered list.',
            );
        });
    }

    public function predictReplacement(Assets $asset): array
    {
        return Cache::remember("asset_ai.replacement.{$asset->id}", 3600, function () use ($asset) {
            $maintCost = (float) $asset->maintenanceSchedules()->where('status', 'completed')->sum('actual_cost');
            $latest    = AssetReplacementPrediction::where('asset_id', $asset->id)->latest('prediction_date')->first();

            $context = [
                'purchase_cost'           => $asset->purchase_cost,
                'cumulative_maintenance'  => $maintCost,
                'age_years'               => $asset->purchase_date?->diffInYears(now()),
                'condition'               => $asset->condition,
                'incidents_total'         => $asset->incidents()->count(),
                'previous_prediction'     => $latest?->only(['recommended_replacement_date', 'roi_if_replaced', 'roi_if_kept']),
            ];

            return $this->ask(
                prompt: 'Recommend keep, replace, or dispose with reasoning. Include estimated payback period for replacement.',
                context: $context,
                system: 'You are a capital planning analyst. Decisions must be ROI-justified.',
            );
        });
    }

    public function summarizeLifecycle(Assets $asset): array
    {
        return Cache::remember("asset_ai.lifecycle.{$asset->id}", 3600, function () use ($asset) {
            $context = [
                'registered_at'      => $asset->created_at?->toDateString(),
                'purchase_date'      => $asset->purchase_date?->toDateString(),
                'current_status'     => $asset->asset_status,
                'condition'          => $asset->condition,
                'assignment_count'   => $asset->assignments()->count(),
                'transfer_count'     => $asset->movements()->count(),
                'maintenance_count'  => $asset->maintenanceSchedules()->count(),
                'incident_count'     => $asset->incidents()->count(),
            ];

            return $this->ask(
                prompt: 'Write a 1-paragraph executive lifecycle narrative for this asset from registration to today.',
                context: $context,
                system: 'You write concise asset lifecycle narratives for executive audiences. Maximum 120 words.',
            );
        });
    }

    public function analyzeUtilization(Assets $asset, int $days = 30): array
    {
        return Cache::remember("asset_ai.utilization.{$asset->id}.{$days}", 1800, function () use ($asset, $days) {
            $logs = $asset->utilizationLogs()
                ->where('log_date', '>=', now()->subDays($days))
                ->get(['log_date', 'hours_used', 'idle_hours', 'utilization_rate'])
                ->toArray();

            return $this->ask(
                prompt: "Analyse this asset's utilization over the last {$days} days. Highlight idle periods and productivity insights.",
                context: ['logs' => $logs, 'asset' => $asset->only(['name', 'asset_number'])],
                system: 'You are a productivity analyst. Cite specific dates from the data.',
            );
        });
    }

    public function summarizeDashboard(array $stats): array
    {
        $cacheKey = 'asset_ai.dashboard.' . md5(json_encode($stats));

        return Cache::remember($cacheKey, 900, fn () => $this->ask(
            prompt: 'Produce a 3-bullet executive summary of the asset portfolio. Flag anything urgent.',
            context: $stats,
            system: 'You produce executive dashboards summaries. Be terse and prioritise risk.',
        ));
    }

    public function getAssetHealthSummary(int $assetId): array
    {
        $asset = Assets::with(['digitalTwin', 'currentAssignment', 'maintenanceSchedules', 'incidents'])->findOrFail($assetId);
        $twin  = $asset->digitalTwin;

        return [
            'asset_id'              => $assetId,
            'asset_name'            => $asset->name,
            'health_score'          => $twin?->health_score ?? 100,
            'risk_level'            => $twin?->risk_level ?? 'low',
            'total_incidents'       => $twin?->total_incidents ?? 0,
            'total_downtime_hours'  => $twin?->total_downtime_hours ?? 0,
            'last_maintenance'      => $twin?->last_maintenance_at?->format('Y-m-d'),
            'predicted_failure_date'=> $twin?->predicted_failure_date?->format('Y-m-d'),
            'recommendations'       => $this->generateRecommendations($asset, $twin),
        ];
    }

    public function predictAssetFailure(int $assetId): array
    {
        $asset      = Assets::findOrFail($assetId);
        $prediction = AssetFailurePrediction::where('asset_id', $assetId)->latest('prediction_date')->first();

        if (! $prediction || $prediction->prediction_date < now()->subDays(30)) {
            $prediction = $this->generateFailurePrediction($asset);
        }

        return [
            'asset_id'               => $assetId,
            'predicted_failure_date' => $prediction->predicted_failure_date->format('Y-m-d'),
            'confidence_score'       => $prediction->confidence_score,
            'failure_type'           => $prediction->failure_type,
            'contributing_factors'   => $prediction->contributing_factors,
            'recommended_action'     => $prediction->recommended_action,
            'estimated_repair_cost'  => $prediction->estimated_repair_cost,
        ];
    }

    public function recommendReplacement(int $assetId): array
    {
        $asset      = Assets::findOrFail($assetId);
        $prediction = AssetReplacementPrediction::where('asset_id', $assetId)->latest('prediction_date')->first();

        if (! $prediction || $prediction->prediction_date < now()->subDays(90)) {
            $prediction = $this->generateReplacementPrediction($asset);
        }

        return [
            'asset_id'                  => $assetId,
            'should_replace'            => $prediction->shouldReplaceNow(),
            'recommended_date'          => $prediction->recommended_replacement_date->format('Y-m-d'),
            'confidence_score'          => $prediction->confidence_score,
            'current_book_value'        => $prediction->current_book_value,
            'estimated_replacement_cost'=> $prediction->estimated_replacement_cost,
            'roi_if_replaced'           => $prediction->roi_if_replaced,
            'roi_if_kept'               => $prediction->roi_if_kept,
            'reasoning'                 => $prediction->reasoning,
        ];
    }

    public function analyzeAssetRisk(int $assetId): array
    {
        $asset       = Assets::with(['digitalTwin', 'riskProfile'])->findOrFail($assetId);
        $riskProfile = $asset->riskProfile;

        return [
            'asset_id'              => $assetId,
            'risk_score'            => $riskProfile?->risk_score ?? 0,
            'risk_level'            => $riskProfile?->risk_level ?? 'low',
            'age_risk'              => $riskProfile?->age_risk_factor ?? 0,
            'condition_risk'        => $riskProfile?->condition_risk_factor ?? 0,
            'incident_risk'         => $riskProfile?->incident_risk_factor ?? 0,
            'maintenance_compliance'=> $riskProfile?->maintenance_compliance_factor ?? 100,
            'financial_exposure'    => $riskProfile?->financial_exposure ?? 0,
            'recommended_action'    => $riskProfile?->recommended_action ?? 'monitor',
        ];
    }

    private function ask(string $prompt, array $context, string $system): array
    {
        try {
            $request = (new AgentRequest(prompt: $prompt, module: 'assets'))
                ->withContext($context)
                ->withSystemPrompt($system);

            $response = $this->registry->for('assets')->ask($request);

            if ($response->failed()) {
                return ['error' => $response->errorMessage, 'fallback' => true];
            }

            return [
                'content'   => $response->content,
                'provider'  => $response->provider,
                'tokens'    => $response->totalTokens,
                'fallback'  => false,
            ];
        } catch (\Throwable $e) {
            Log::warning('AssetAIModule.ask failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage(), 'fallback' => true];
        }
    }

    private function generateRecommendations(Assets $asset, ?AssetDigitalTwin $twin): array
    {
        $out = [];
        if ($twin && $twin->health_score < 50) {
            $out[] = 'Schedule immediate inspection — health score critical';
        }
        if ($twin && $twin->total_incidents > 5) {
            $out[] = 'High incident rate — consider replacement evaluation';
        }
        if ($twin && $twin->last_maintenance_at && $twin->last_maintenance_at < now()->subMonths(6)) {
            $out[] = 'Overdue maintenance — schedule preventive service';
        }
        return $out;
    }

    private function generateFailurePrediction(Assets $asset): AssetFailurePrediction
    {
        $ageYears   = $asset->purchase_date ? $asset->purchase_date->diffInYears(now()) : 0;
        $incidents  = $asset->incidents()->count();
        $confidence = min(100, 50 + ($ageYears * 5) + ($incidents * 10));
        $daysOut    = max(30, 365 - ($ageYears * 30) - ($incidents * 15));

        return AssetFailurePrediction::create([
            'asset_id'               => $asset->id,
            'prediction_date'        => now(),
            'predicted_failure_date' => now()->addDays($daysOut),
            'confidence_score'       => $confidence,
            'failure_type'           => 'mechanical',
            'contributing_factors'   => ['age', 'incident_history'],
            'recommended_action'     => 'Schedule preventive maintenance',
            'estimated_repair_cost'  => (float) $asset->purchase_cost * 0.3,
            'model_version'          => 'v1.0',
        ]);
    }

    private function generateReplacementPrediction(Assets $asset): AssetReplacementPrediction
    {
        $bookValue  = (float) $asset->purchase_cost * 0.5;
        $maintCost  = (float) ($asset->maintenances()->sum('cost') ?? 0);

        return AssetReplacementPrediction::create([
            'asset_id'                     => $asset->id,
            'prediction_date'              => now(),
            'recommended_replacement_date' => now()->addYears(2),
            'confidence_score'             => 70,
            'current_book_value'           => $bookValue,
            'estimated_replacement_cost'   => (float) $asset->purchase_cost * 1.2,
            'total_maintenance_cost_ytd'   => $maintCost,
            'maintenance_cost_trend'       => 'increasing',
            'roi_if_replaced'              => 15.5,
            'roi_if_kept'                  => 8.2,
            'reasoning'                    => 'Maintenance costs trending upward — replacement within 2 years recommended',
            'model_version'                => 'v1.0',
        ]);
    }
}
