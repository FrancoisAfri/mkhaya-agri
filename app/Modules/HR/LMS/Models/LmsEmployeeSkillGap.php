<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsEmployeeSkillGap extends Model
{
    protected $table = 'employee_skill_gaps';

    public const STATUSES = ['open', 'in_progress', 'closed'];

    protected $fillable = [
        'user_id',
        'skill_id',
        'position_id',
        'current_level',
        'required_level',
        'gap',
        'recommended_course_id',
        'status',
        'identified_at',
        'closed_at',
    ];

    protected $casts = [
        'current_level'  => 'integer',
        'required_level' => 'integer',
        'gap'            => 'integer',
        'identified_at'  => 'datetime',
        'closed_at'      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(LmsSkill::class, 'skill_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function recommendedCourse(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'recommended_course_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
