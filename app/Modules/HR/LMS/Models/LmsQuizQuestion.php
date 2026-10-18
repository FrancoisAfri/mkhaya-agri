<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsQuizQuestion extends Model
{
    protected $table = 'lms_quiz_questions';

    protected $fillable = [
        'course_id',
        'question',
        'type',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(LmsQuizOption::class, 'question_id')->orderBy('sort_order');
    }
}
