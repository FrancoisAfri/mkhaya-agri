<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryRiskPrediction extends Model
{
    protected $table = 'disciplinary_risk_predictions';

    protected $fillable = [
        'employee_id', 'prediction_type', 'probability', 'risk_level',
        'prediction_window_end', 'reasoning', 'contributing_factors',
        'recommended_actions', 'actioned', 'actioned_by', 'actioned_at',
        'is_ai_generated', 'ai_provider', 'ai_model', 'generated_by',
    ];

    protected $casts = [
        'prediction_window_end'  => 'date',
        'contributing_factors'   => 'array',
        'recommended_actions'    => 'array',
        'actioned'               => 'boolean',
        'is_ai_generated'        => 'boolean',
        'actioned_at'            => 'datetime',
        'probability'            => 'float',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }

    public function scopeActive($query)
    {
        return $query->where('actioned', false)
            ->where('prediction_window_end', '>=', now()->toDateString());
    }
}
