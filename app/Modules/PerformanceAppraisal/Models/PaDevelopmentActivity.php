<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaDevelopmentActivity extends Model
{
    protected $table = 'pa_development_activities';

    protected $fillable = [
        'plan_id', 'title', 'activity_type', 'description',
        'target_date', 'completed_date', 'status',
        'provider', 'cost', 'evidence_notes', 'sort_order',
        'lms_course_id', 'lms_enrollment_id',
    ];

    protected $casts = [
        'target_date'    => 'date',
        'completed_date' => 'date',
        'cost'           => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaDevelopmentPlan::class, 'plan_id');
    }
}
