<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsAiRecommendation extends Model
{
    protected $table = 'lms_ai_recommendations';

    public const TYPES = [
        'job_title'            => 'Job Title Match',
        'performance_gap'      => 'Performance Gap',
        'failed_topics'        => 'Failed Topics',
        'career_path'          => 'Career Path',
        'policy_requirement'   => 'Policy Requirement',
        'refresher'            => 'Refresher Training',
    ];

    public const STATUSES = ['pending', 'accepted', 'dismissed'];

    protected $fillable = [
        'user_id',
        'course_id',
        'recommendation_type',
        'reason',
        'priority',
        'ai_context',
        'ai_response',
        'status',
        'generated_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'ai_context'   => 'array',
        'ai_response'  => 'array',
        'generated_at' => 'datetime',
        'expires_at'   => 'datetime',
        'priority'     => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
