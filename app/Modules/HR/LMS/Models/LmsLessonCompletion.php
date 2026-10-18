<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsLessonCompletion extends Model
{
    protected $table = 'lms_lesson_completions';

    protected $fillable = [
        'enrollment_id',
        'lesson_id',
        'completed_at',
        'time_spent_mins',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class, 'lesson_id');
    }
}
