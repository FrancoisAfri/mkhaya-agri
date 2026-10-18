<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsLesson extends Model
{
    protected $table = 'lms_lessons';

    protected $fillable = [
        'module_id',
        'course_id',
        'title',
        'type',
        'content_body',
        'external_url',
        'duration_mins',
        'sort_order',
        'is_preview',
    ];

    protected $casts = [
        'duration_mins' => 'integer',
        'sort_order'    => 'integer',
        'is_preview'    => 'boolean',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(LmsCourseModule::class, 'module_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(LmsCourseMaterial::class, 'lesson_id')->orderBy('sort_order');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(LmsLessonCompletion::class, 'lesson_id');
    }
}
