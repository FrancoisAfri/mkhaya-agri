<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsCourseContent extends Model
{
    protected $table = 'lms_course_contents';

    protected $fillable = [
        'course_id',
        'type',
        'title',
        'body',
        'url',
        'file_path',
        'sort_order',
        'duration_mins',
    ];

    protected $casts = [
        'sort_order'    => 'integer',
        'duration_mins' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
