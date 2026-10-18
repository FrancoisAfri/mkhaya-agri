<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsCourseModule extends Model
{
    protected $table = 'lms_course_modules';

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(LmsLesson::class, 'module_id')->orderBy('sort_order');
    }
}
