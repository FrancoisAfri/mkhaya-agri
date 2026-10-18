<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsCoursePrerequisite extends Model
{
    protected $table = 'lms_course_prerequisites';

    protected $fillable = [
        'course_id',
        'prerequisite_course_id',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function prerequisiteCourse(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'prerequisite_course_id');
    }
}
