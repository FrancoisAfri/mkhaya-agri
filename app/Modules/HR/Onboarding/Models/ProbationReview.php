<?php

namespace App\Modules\HR\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProbationReview extends Model
{
    protected $table = 'probation_reviews';

    protected $fillable = [
        'probation_period_id',
        'review_date',
        'review_type',
        'performance_score',
        'manager_feedback',
        'employee_feedback',
        'areas_of_improvement',
        'recommendation',
        'reviewed_by',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'review_date'       => 'date',
        'completed_at'      => 'datetime',
        'performance_score' => 'integer',
    ];

    public function probationPeriod(): BelongsTo
    {
        return $this->belongsTo(ProbationPeriod::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'reviewed_by');
    }
}
