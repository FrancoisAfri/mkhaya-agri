<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehiclePrediction extends Model
{
    protected $table = 'fleet_vehicle_predictions';

    protected $fillable = [
        'vehicle_id', 'risk_score', 'predicted_service_date',
        'top_risk_factors', 'feature_values', 'prediction_method',
        'predicted_at', 'alert_sent', 'maintenance_job_id',
    ];

    protected $casts = [
        'risk_score'             => 'decimal:2',
        'predicted_service_date' => 'date',
        'top_risk_factors'       => 'array',
        'feature_values'         => 'array',
        'predicted_at'           => 'datetime',
        'alert_sent'             => 'boolean',
    ];

    public function vehicle(): BelongsTo { return $this->belongsTo(FleetVehicle::class); }

    public function isHighRisk(): bool   { return $this->risk_score >= 70; }
    public function isMediumRisk(): bool { return $this->risk_score >= 40 && $this->risk_score < 70; }

    public function riskLevel(): string
    {
        return match(true) {
            $this->risk_score >= 70 => 'high',
            $this->risk_score >= 40 => 'medium',
            default                 => 'low',
        };
    }
}
