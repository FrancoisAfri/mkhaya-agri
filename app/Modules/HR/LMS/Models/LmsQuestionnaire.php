<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LmsQuestionnaire extends Model
{
    use SoftDeletes;

    protected $table = 'lms_questionnaires';

    protected $fillable = [
        'uuid', 'title', 'description', 'instructions', 'type',
        'time_limit_mins', 'pass_score', 'allow_retake', 'max_retakes',
        'randomise_questions', 'randomise_options', 'show_answers_after', 'created_by',
    ];

    protected $casts = [
        'time_limit_mins'     => 'integer',
        'pass_score'          => 'integer',
        'max_retakes'         => 'integer',
        'allow_retake'        => 'boolean',
        'randomise_questions' => 'boolean',
        'randomise_options'   => 'boolean',
        'show_answers_after'  => 'boolean',
    ];

    // Auto-graded types (has correct answers)
    public const AUTO_GRADED = ['mcq', 'multi_select', 'true_false', 'yes_no', 'policy_ack'];

    // Labels for the UI
    public const TYPE_LABELS = [
        'questionnaire'        => 'Questionnaire',
        'pre_assessment'       => 'Pre-Training Assessment',
        'post_assessment'      => 'Post-Training Assessment',
        'knowledge_check'      => 'Knowledge Check',
        'final_test'           => 'Final Course Test',
        'practical_assessment' => 'Practical Assessment',
        'self_assessment'      => 'Employee Self-Assessment',
        'manager_observation'  => 'Manager Observation',
        'feedback'             => 'Course Feedback',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function questions(): HasMany
    {
        return $this->hasMany(LmsQuestion::class, 'questionnaire_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LmsQuestionnaireAssignment::class, 'questionnaire_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LmsQuestionnaireAttempt::class, 'questionnaire_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasManualQuestions(): bool
    {
        return $this->questions()
            ->whereNotIn('type', self::AUTO_GRADED)
            ->exists();
    }

    public function totalPoints(): int
    {
        return (int) $this->questions()->sum('points');
    }
}
