<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsCourseSkill extends Model
{
    protected $table = 'course_skills';

    protected $fillable = [
        'course_id',
        'skill_id',
        'skill_level_gained',
    ];

    protected $casts = [
        'skill_level_gained' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(LmsSkill::class, 'skill_id');
    }
}
