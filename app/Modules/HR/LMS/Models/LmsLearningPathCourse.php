<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsLearningPathCourse extends Model
{
    protected $table = 'lms_learning_path_courses';

    protected $fillable = [
        'path_id',
        'course_id',
        'sort_order',
        'is_required',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'is_required' => 'boolean',
    ];

    public function path(): BelongsTo
    {
        return $this->belongsTo(LmsLearningPath::class, 'path_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
