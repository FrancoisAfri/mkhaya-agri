<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaProbationRiskScore extends Model
{
    protected $table = 'pa_probation_risk_scores';

    protected $fillable = [
        'review_id', 'risk_score', 'risk_level', 'success_probability',
        'recommendation', 'factors', 'is_ai_generated', 'scored_by',
    ];

    protected $casts = [
        'factors'             => 'array',
        'is_ai_generated'     => 'boolean',
        'success_probability' => 'decimal:2',
    ];

    public function review(): BelongsTo   { return $this->belongsTo(PaProbationReview::class, 'review_id'); }
    public function scorer(): BelongsTo   { return $this->belongsTo(User::class, 'scored_by'); }
}
