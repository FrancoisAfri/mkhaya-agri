<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsLearningGapReport extends Model
{
    protected $table = 'lms_learning_gap_reports';

    protected $fillable = [
        'enrollment_id', 'user_id', 'course_id', 'report_type',
        'guessing_detected', 'rushed_detected', 'low_engagement_detected',
        'total_understanding_score', 'pass_threshold', 'passed',
        'weak_areas_count', 'recommendations',
        'generated_at', 'generated_by',
    ];

    protected $casts = [
        'guessing_detected'        => 'boolean',
        'rushed_detected'          => 'boolean',
        'low_engagement_detected'  => 'boolean',
        'total_understanding_score'=> 'float',
        'pass_threshold'           => 'float',
        'passed'                   => 'boolean',
        'recommendations'          => 'array',
        'generated_at'             => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
