<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsPromotionReadiness extends Model
{
    protected $table = 'lms_promotion_readiness';

    protected $fillable = [
        'user_id', 'cycle_id', 'overall_training_score',
        'completion_rate', 'pass_rate', 'understanding_avg',
        'development_completion_pct', 'is_promotion_ready',
        'readiness_notes', 'computed_at',
    ];

    protected $casts = [
        'overall_training_score'    => 'float',
        'completion_rate'           => 'float',
        'pass_rate'                 => 'float',
        'understanding_avg'         => 'float',
        'development_completion_pct'=> 'float',
        'is_promotion_ready'        => 'boolean',
        'computed_at'               => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function readinessLevel(): string
    {
        $score = $this->overall_training_score ?? 0;
        if ($score >= 85) return 'ready_now';
        if ($score >= 70) return 'ready';
        if ($score >= 50) return 'developing';
        return 'not_ready';
    }

    public function readinessColor(): string
    {
        return match ($this->readinessLevel()) {
            'ready_now'  => 'success',
            'ready'      => 'info',
            'developing' => 'warning',
            default      => 'error',
        };
    }
}
