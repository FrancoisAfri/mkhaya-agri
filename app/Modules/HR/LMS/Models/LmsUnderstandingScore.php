<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsUnderstandingScore extends Model
{
    protected $table = 'lms_understanding_scores';

    protected $fillable = [
        'enrollment_id', 'user_id', 'course_id',
        'lesson_completion_score', 'quiz_score', 'practical_score',
        'time_score', 'manager_score', 'total_score', 'grade',
        'guessing_detected', 'rushed_detected', 'low_engagement_detected',
        'is_certificate_blocked', 'certificate_blocked_reason',
        'refresher_recommended',
        'manager_confirmed', 'manager_confirmed_by', 'manager_confirmed_at',
        'calculated_at',
    ];

    protected $casts = [
        'lesson_completion_score' => 'float',
        'quiz_score'              => 'float',
        'practical_score'         => 'float',
        'time_score'              => 'float',
        'manager_score'           => 'float',
        'total_score'             => 'float',
        'guessing_detected'       => 'boolean',
        'rushed_detected'         => 'boolean',
        'low_engagement_detected' => 'boolean',
        'is_certificate_blocked'  => 'boolean',
        'refresher_recommended'   => 'boolean',
        'manager_confirmed'       => 'boolean',
        'manager_confirmed_at'    => 'datetime',
        'calculated_at'           => 'datetime',
    ];

    // Grade colour for UI
    public const GRADE_COLORS = [
        'A' => 'success',
        'B' => 'light-green',
        'C' => 'orange',
        'D' => 'deep-orange',
        'F' => 'error',
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

    public function managerConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_confirmed_by');
    }
}
