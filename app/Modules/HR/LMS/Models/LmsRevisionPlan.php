<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsRevisionPlan extends Model
{
    protected $table = 'lms_revision_plans';

    public const STATUSES = ['pending', 'active', 'completed'];

    protected $fillable = [
        'user_id',
        'enrollment_id',
        'course_id',
        'topic_areas',
        'weak_areas',
        'ai_suggestions',
        'estimated_hours',
        'status',
        'generated_at',
        'generated_by',
    ];

    protected $casts = [
        'topic_areas'     => 'array',
        'weak_areas'      => 'array',
        'ai_suggestions'  => 'array',
        'estimated_hours' => 'float',
        'generated_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
