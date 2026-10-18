<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * M2 — Asset Risk Profile
 * 
 * Calculated risk assessment based on age, condition, incident history, maintenance compliance.
 * Updated by scheduled job (GenerateAssetRiskScoresJob).
 */
class AssetRiskProfile extends Model
{
    protected $table = 'asset_risk_profiles';

    protected $fillable = [
        'uuid',
        'asset_id',
        'digital_twin_id',
        'risk_score',                    // 0-100
        'risk_level',                    // low | medium | high | critical
        'age_risk_factor',               // 0-100
        'condition_risk_factor',         // 0-100
        'incident_risk_factor',          // 0-100
        'maintenance_compliance_factor', // 0-100
        'utilization_risk_factor',       // 0-100
        'financial_exposure',            // Current book value at risk
        'recommended_action',            // monitor | schedule_maintenance | replace | retire
        'last_calculated_at',
        'next_review_at',
    ];

    protected $casts = [
        'risk_score'                    => 'integer',
        'age_risk_factor'               => 'integer',
        'condition_risk_factor'         => 'integer',
        'incident_risk_factor'          => 'integer',
        'maintenance_compliance_factor' => 'integer',
        'utilization_risk_factor'       => 'integer',
        'financial_exposure'            => 'decimal:2',
        'last_calculated_at'            => 'datetime',
        'next_review_at'                => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    // Helpers
    public function isCritical(): bool
    {
        return $this->risk_level === 'critical';
    }

    /**
     * Recalculate composite risk score from current asset state.
     *
     * Weights:
     *   age            25%
     *   condition      20%
     *   incidents      25%
     *   maintenance    20%
     *   utilization    10%
     *
     * Computes individual factors and stores the composite score
     * plus level on the row. Returns the freshly-updated profile.
     */
    public function recalculate(): self
    {
        $asset   = $this->asset;
        $twin    = $this->digitalTwin ?? null;

        $ageRisk        = $this->ageRiskFactor($asset?->purchase_date);
        $conditionRisk  = $this->conditionRiskFactor($asset?->condition);
        $incidentRisk   = $this->incidentRiskFactor($asset?->id);
        $maintRisk      = $this->maintenanceRiskFactor($asset?->id);
        $utilRisk       = $this->utilizationRiskFactor($twin);

        $score = (int) round(
            $ageRisk       * 0.25 +
            $conditionRisk * 0.20 +
            $incidentRisk  * 0.25 +
            $maintRisk     * 0.20 +
            $utilRisk      * 0.10
        );

        // Only persist columns that are guaranteed to exist across schema versions.
        $this->risk_score         = $score;
        $this->risk_level         = $this->levelForScore($score);
        $this->last_calculated_at = now();

        // Persist individual factors when the columns exist (older schema)
        $columns = Schema::getColumnListing($this->getTable());
        if (in_array('age_risk_factor', $columns, true)) {
            $this->age_risk_factor               = $ageRisk;
            $this->condition_risk_factor         = $conditionRisk;
            $this->incident_risk_factor          = $incidentRisk;
            $this->maintenance_compliance_factor = $maintRisk;
            $this->utilization_risk_factor       = $utilRisk;
        }

        $this->save();

        return $this;
    }

    public function digitalTwin()
    {
        // Prefer the join via digital_twin_id when present, else fall back to asset_id.
        if (Schema::hasColumn($this->getTable(), 'digital_twin_id')) {
            return $this->belongsTo(AssetDigitalTwin::class, 'digital_twin_id');
        }
        return $this->hasOne(AssetDigitalTwin::class, 'asset_id', 'asset_id');
    }

    private function ageRiskFactor(mixed $purchaseDate): int
    {
        if (! $purchaseDate) {
            return 0;
        }
        $carbon = $purchaseDate instanceof \DateTimeInterface
            ? \Carbon\Carbon::instance($purchaseDate)
            : \Carbon\Carbon::parse((string) $purchaseDate);
        $years = (int) abs(now()->diffInYears($carbon));
        return (int) min(100, $years * 10);
    }

    private function conditionRiskFactor(?string $condition): int
    {
        return match ($condition) {
            'new'  => 0,
            'good' => 20,
            'fair' => 50,
            'poor' => 90,
            default => 30,
        };
    }

    private function incidentRiskFactor(?int $assetId): int
    {
        if (! $assetId) {
            return 0;
        }
        $count = AssetIncident::where('asset_id', $assetId)
            ->where('is_resolved', false)
            ->count();
        return (int) min(100, $count * 25);
    }

    private function maintenanceRiskFactor(?int $assetId): int
    {
        if (! $assetId) {
            return 0;
        }
        $overdue = AssetMaintenanceSchedule::where('asset_id', $assetId)
            ->where('status', 'scheduled')
            ->whereDate('due_date', '<', now())
            ->count();
        return (int) min(100, $overdue * 30);
    }

    private function utilizationRiskFactor(?AssetDigitalTwin $twin): int
    {
        if (! $twin) {
            return 0;
        }
        $rate = (float) ($twin->utilization_rate ?? 50);
        // Over-utilized or under-utilized both signal risk
        if ($rate >= 90) {
            return 70;
        }
        if ($rate <= 10) {
            return 60;
        }
        return 20;
    }

    private function levelForScore(int $score): string
    {
        return match (true) {
            $score >= 80 => 'critical',
            $score >= 60 => 'high',
            $score >= 30 => 'medium',
            default      => 'low',
        };
    }

    public function needsImmediateAction(): bool
    {
        return $this->risk_score >= 80 || $this->risk_level === 'critical';
    }
}
