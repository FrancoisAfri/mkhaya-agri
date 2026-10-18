<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsEffectivenessSnapshot extends Model
{
    protected $table = 'lms_effectiveness_snapshots';

    protected $fillable = [
        'course_id', 'period_start', 'period_end',
        'completion_rate', 'pass_rate', 'avg_score', 'avg_understanding_score',
        'understanding_improvement', 'repeated_failure_rate',
        'post_training_performance_delta', 'appraisal_delta', 'incident_delta',
        'roi_pct', 'computed_at', 'snapshot_data',
    ];

    protected $casts = [
        'period_start'                    => 'date',
        'period_end'                      => 'date',
        'completion_rate'                 => 'float',
        'pass_rate'                       => 'float',
        'avg_score'                       => 'float',
        'avg_understanding_score'         => 'float',
        'understanding_improvement'       => 'float',
        'repeated_failure_rate'           => 'float',
        'post_training_performance_delta' => 'float',
        'appraisal_delta'                 => 'float',
        'incident_delta'                  => 'float',
        'roi_pct'                         => 'float',
        'computed_at'                     => 'datetime',
        'snapshot_data'                   => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
