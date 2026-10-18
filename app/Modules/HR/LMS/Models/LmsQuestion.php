<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LmsQuestion extends Model
{
    protected $table = 'lms_questions';

    protected $fillable = [
        'uuid', 'questionnaire_id', 'bank_id', 'source_question_id',
        'type', 'question_text', 'context_text', 'correct_answer',
        'explanation', 'points', 'sort_order',
    ];

    protected $casts = [
        'correct_answer' => 'array',
        'points'         => 'integer',
        'sort_order'     => 'integer',
    ];

    public const TYPE_LABELS = [
        'mcq'          => 'Multiple Choice',
        'multi_select' => 'Multi-Select',
        'true_false'   => 'True or False',
        'short_answer' => 'Short Answer',
        'long_answer'  => 'Long Answer',
        'file_upload'  => 'File Upload',
        'practical'    => 'Practical Evidence',
        'rating_scale' => 'Rating Scale',
        'yes_no'       => 'Yes / No',
        'scenario'     => 'Scenario-Based',
        'policy_ack'   => 'Policy Acknowledgement',
    ];

    public const HAS_OPTIONS = ['mcq', 'multi_select', 'true_false', 'yes_no', 'rating_scale'];
    public const AUTO_GRADED = ['mcq', 'multi_select', 'true_false', 'yes_no', 'policy_ack'];
    public const NEEDS_REVIEW = ['short_answer', 'long_answer', 'file_upload', 'practical', 'scenario'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaire::class, 'questionnaire_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionBank::class, 'bank_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(LmsQuestionOption::class, 'question_id')->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LmsQuestionResponse::class, 'question_id');
    }

    public function isAutoGraded(): bool
    {
        return in_array($this->type, self::AUTO_GRADED);
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, self::HAS_OPTIONS);
    }

    public function copyToQuestionnaire(int $questionnaireId): self
    {
        $copy = $this->replicate(['id', 'uuid', 'questionnaire_id', 'bank_id', 'source_question_id']);
        $copy->uuid               = Str::uuid()->toString();
        $copy->questionnaire_id   = $questionnaireId;
        $copy->bank_id            = null;
        $copy->source_question_id = $this->id;
        $copy->save();

        foreach ($this->options as $option) {
            $copy->options()->create($option->only(['option_text', 'is_correct', 'rating_value', 'sort_order']));
        }

        return $copy;
    }
}
