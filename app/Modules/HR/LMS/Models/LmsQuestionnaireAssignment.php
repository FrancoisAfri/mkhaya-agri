<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsQuestionnaireAssignment extends Model
{
    protected $table = 'lms_questionnaire_assignments';

    protected $fillable = [
        'questionnaire_id', 'course_id', 'lesson_id',
        'assignment_type', 'is_required', 'due_days_after_enrol',
    ];

    protected $casts = [
        'is_required'         => 'boolean',
        'due_days_after_enrol'=> 'integer',
    ];

    public const TYPE_LABELS = [
        'pre_course'   => 'Pre-Course',
        'post_course'  => 'Post-Course',
        'lesson_check' => 'Lesson Check',
        'standalone'   => 'Standalone',
    ];

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaire::class, 'questionnaire_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class, 'lesson_id');
    }
}
