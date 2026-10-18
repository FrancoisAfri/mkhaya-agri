<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsAssessmentScore extends Model
{
    protected $table = 'lms_assessment_scores';

    protected $fillable = [
        'attempt_id', 'questionnaire_id', 'user_id',
        'total_points', 'auto_earned_points', 'manual_earned_points', 'total_earned_points',
        'percentage', 'passed', 'is_final', 'finalized_at', 'finalized_by',
    ];

    protected $casts = [
        'auto_earned_points'   => 'float',
        'manual_earned_points' => 'float',
        'total_earned_points'  => 'float',
        'percentage'           => 'float',
        'passed'               => 'boolean',
        'is_final'             => 'boolean',
        'finalized_at'         => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaireAttempt::class, 'attempt_id');
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaire::class, 'questionnaire_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
