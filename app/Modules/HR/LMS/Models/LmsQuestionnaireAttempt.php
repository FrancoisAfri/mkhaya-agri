<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class LmsQuestionnaireAttempt extends Model
{
    protected $table = 'lms_questionnaire_attempts';

    protected $fillable = [
        'uuid', 'questionnaire_id', 'user_id', 'enrollment_id',
        'assignment_id', 'status', 'score', 'attempt_number',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'score'          => 'float',
        'attempt_number' => 'integer',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LmsQuestionResponse::class, 'attempt_id');
    }

    public function manualMarkings(): HasMany
    {
        return $this->hasMany(LmsManualMarking::class, 'attempt_id');
    }

    public function assessmentScore(): HasOne
    {
        return $this->hasOne(LmsAssessmentScore::class, 'attempt_id');
    }

    public function isPassed(): bool
    {
        return $this->status === 'passed';
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'passed', 'failed']);
    }

    /**
     * Grade auto-gradable responses, queue manual questions for review,
     * and write an initial LmsAssessmentScore record.
     */
    public function autoGrade(): void
    {
        $questionnaire = $this->questionnaire()->with('questions.options')->first();
        $questions     = $questionnaire->questions;
        $totalPoints   = $questions->sum('points');

        $autoEarned = 0;
        $hasManual  = false;

        foreach ($questions as $question) {
            $response = $this->responses()->where('question_id', $question->id)->first();
            if (!$response) {
                continue;
            }

            if ($question->isAutoGraded()) {
                $correct = $this->checkAutoAnswer($question, $response);
                $pts     = $correct ? $question->points : 0;
                $response->update([
                    'is_correct'     => $correct,
                    'points_awarded' => $pts,
                ]);
                $autoEarned += $pts;
            } else {
                $hasManual = true;
                LmsManualMarking::firstOrCreate(
                    ['response_id' => $response->id],
                    [
                        'attempt_id'       => $this->id,
                        'question_id'      => $question->id,
                        'questionnaire_id' => $this->questionnaire_id,
                        'user_id'          => $this->user_id,
                        'max_points'       => $question->points,
                        'status'           => 'pending',
                    ]
                );
            }
        }

        $score = $totalPoints > 0 ? round(($autoEarned / $totalPoints) * 100, 2) : null;

        $status = $hasManual
            ? 'pending_review'
            : ($questionnaire->pass_score === null
                ? 'completed'
                : ($score >= $questionnaire->pass_score ? 'passed' : 'failed'));

        $this->update([
            'score'        => $score,
            'status'       => $status,
            'completed_at' => now(),
        ]);

        // Write an initial score record (marked not-final if manual questions exist)
        LmsAssessmentScore::updateOrCreate(
            ['attempt_id' => $this->id],
            [
                'questionnaire_id'    => $this->questionnaire_id,
                'user_id'             => $this->user_id,
                'total_points'        => $totalPoints,
                'auto_earned_points'  => $autoEarned,
                'manual_earned_points'=> 0,
                'total_earned_points' => $autoEarned,
                'percentage'          => $score,
                'passed'              => !$hasManual && $status === 'passed',
                'is_final'            => !$hasManual,
                'finalized_at'        => !$hasManual ? now() : null,
            ]
        );
    }

    /**
     * Called after all manual markings are reviewed.
     * Recalculates total score including manual marks and finalises the attempt.
     */
    public function finalizeScore(int $finalizedBy): void
    {
        $questionnaire = $this->questionnaire()->with('questions')->first();
        $totalPoints   = $questionnaire->questions->sum('points');

        $autoQuestionIds   = $questionnaire->questions->filter(fn ($q) => $q->isAutoGraded())->pluck('id');
        $manualQuestionIds = $questionnaire->questions->filter(fn ($q) => !$q->isAutoGraded())->pluck('id');

        $autoEarned   = (float) $this->responses()->whereIn('question_id', $autoQuestionIds)->sum('points_awarded');
        $manualEarned = (float) $this->responses()->whereIn('question_id', $manualQuestionIds)->sum('points_awarded');
        $totalEarned  = $autoEarned + $manualEarned;

        $percentage = $totalPoints > 0 ? round(($totalEarned / $totalPoints) * 100, 2) : null;

        $status = $questionnaire->pass_score === null
            ? 'completed'
            : ($percentage >= $questionnaire->pass_score ? 'passed' : 'failed');

        $this->update(['score' => $percentage, 'status' => $status]);

        LmsAssessmentScore::updateOrCreate(
            ['attempt_id' => $this->id],
            [
                'questionnaire_id'    => $this->questionnaire_id,
                'user_id'             => $this->user_id,
                'total_points'        => $totalPoints,
                'auto_earned_points'  => $autoEarned,
                'manual_earned_points'=> $manualEarned,
                'total_earned_points' => $totalEarned,
                'percentage'          => $percentage,
                'passed'              => $status === 'passed',
                'is_final'            => true,
                'finalized_at'        => now(),
                'finalized_by'        => $finalizedBy,
            ]
        );
    }

    private function checkAutoAnswer(LmsQuestion $question, LmsQuestionResponse $response): bool
    {
        $json = $response->response_json ?? [];

        return match ($question->type) {
            'mcq', 'true_false', 'yes_no' => (function () use ($question, $json) {
                $correctIds = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->toArray();
                $submitted  = collect($json['option_ids'] ?? [])->sort()->values()->toArray();
                return $correctIds === $submitted;
            })(),
            'multi_select' => (function () use ($question, $json) {
                $correctIds = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->toArray();
                $submitted  = collect($json['option_ids'] ?? [])->sort()->values()->toArray();
                return $correctIds === $submitted;
            })(),
            'policy_ack' => (bool) ($json['acknowledged'] ?? false),
            default      => false,
        };
    }
}
