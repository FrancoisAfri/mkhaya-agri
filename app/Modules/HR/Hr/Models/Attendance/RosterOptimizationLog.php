<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterOptimizationLog extends Model
{
    protected $fillable = [
        'roster_schedule_id', 'optimization_type',
        'coverage_score_before', 'coverage_score_after',
        'balance_score_before', 'balance_score_after',
        'changes_made', 'recommendations', 'ai_report', 'triggered_by',
    ];

    protected $casts = [
        'changes_made'    => 'array',
        'recommendations' => 'array',
    ];

    const TYPES = [
        'staffing_levels'   => 'Staffing Levels',
        'shift_distribution'=> 'Shift Distribution',
        'fatigue_reduction' => 'Fatigue Reduction',
        'full'              => 'Full Optimisation',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RosterSchedule::class, 'roster_schedule_id');
    }
}
