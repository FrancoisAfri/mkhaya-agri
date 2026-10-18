<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsWeakLearningArea extends Model
{
    protected $table = 'lms_weak_learning_areas';

    protected $fillable = [
        'user_id', 'course_id', 'questionnaire_id', 'question_id',
        'topic', 'failure_count', 'average_score', 'last_failed_at',
        'is_resolved', 'refresher_course_id',
    ];

    protected $casts = [
        'average_score'  => 'float',
        'is_resolved'    => 'boolean',
        'last_failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaire::class, 'questionnaire_id');
    }

    public function refresherCourse(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'refresher_course_id');
    }
}
