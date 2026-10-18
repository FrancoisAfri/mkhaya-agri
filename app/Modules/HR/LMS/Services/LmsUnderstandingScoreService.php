<?php

namespace App\Modules\HR\LMS\Services;

use App\Modules\HR\LMS\Models\LmsAssessmentScore;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsLesson;
use App\Modules\HR\LMS\Models\LmsLessonCompletion;
use App\Modules\HR\LMS\Models\LmsLearningGapReport;
use App\Modules\HR\LMS\Models\LmsQuestionnaire;
use App\Modules\HR\LMS\Models\LmsQuestionnaireAttempt;
use App\Modules\HR\LMS\Models\LmsQuestionResponse;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Models\LmsWeakLearningArea;
use Illuminate\Support\Collection;

class LmsUnderstandingScoreService
{
    // Weighted contribution of each component (must sum to 1.0)
    public const WEIGHTS = [
        'lesson_completion' => 0.20,
        'quiz'              => 0.40,
        'practical'         => 0.20,
        'time'              => 0.10,
        'manager'           => 0.10,
    ];

    public const GRADE_THRESHOLDS = ['A' => 90, 'B' => 75, 'C' => 60, 'D' => 50];

    // Seconds-per-question threshold below which guessing is flagged
    private const GUESS_SECS_PER_Q = 5;

    // Fraction of expected duration below which rushed completion is flagged
    private const RUSHED_RATIO = 0.25;

    // ── Main entry point ─────────────────────────────────────────────────────

    /**
     * Compute the full understanding score for an enrollment.
     * Preserves any existing manager confirmation before recalculating.
     */
    public function calculateUnderstandingScore(LmsEnrollment $enrollment): LmsUnderstandingScore
    {
        $enrollment->loadMissing(['course', 'user']);

        // ── 1. Lesson completion (0-100) ──────────────────────────────────
        $totalLessons     = LmsLesson::where('course_id', $enrollment->course_id)->count();
        $completedLessons = LmsLessonCompletion::where('enrollment_id', $enrollment->id)->count();
        $lessonScore      = $totalLessons > 0 ? ($completedLessons / $totalLessons) * 100 : 0;

        // ── 2 & 3. Quiz + practical scores from finalised assessment scores ─
        $quizTypes = ['questionnaire', 'pre_assessment', 'post_assessment', 'knowledge_check', 'final_test'];

        $allScores = LmsAssessmentScore::whereHas(
            'attempt',
            fn ($q) => $q->where('enrollment_id', $enrollment->id)
        )->with('questionnaire')->where('is_final', true)->get();

        $quizPercentages      = $allScores->filter(fn ($s) => in_array($s->questionnaire->type, $quizTypes))->pluck('percentage');
        $practicalPercentages = $allScores->filter(fn ($s) => $s->questionnaire->type === 'practical_assessment')->pluck('percentage');

        $quizScore      = $quizPercentages->count() > 0 ? $quizPercentages->avg() : 0;
        // Fall back to quiz score when no dedicated practical assessments exist
        $practicalScore = $practicalPercentages->count() > 0 ? $practicalPercentages->avg() : $quizScore;

        // ── 4. Time score (0-100) ─────────────────────────────────────────
        $expectedMins = $enrollment->course->duration_mins ?? 0;
        $actualMins   = $enrollment->time_spent_mins ?? 0;
        $timeScore    = $expectedMins > 0 ? min(100.0, ($actualMins / $expectedMins) * 100) : 0;

        // ── 5. Manager score (0 or 100) ────────────────────────────────────
        $existing       = LmsUnderstandingScore::where('enrollment_id', $enrollment->id)->first();
        $managerScore   = ($existing && $existing->manager_confirmed) ? 100 : 0;

        // ── Weighted total ────────────────────────────────────────────────
        $total = ($lessonScore    * self::WEIGHTS['lesson_completion'])
               + ($quizScore      * self::WEIGHTS['quiz'])
               + ($practicalScore * self::WEIGHTS['practical'])
               + ($timeScore      * self::WEIGHTS['time'])
               + ($managerScore   * self::WEIGHTS['manager']);

        $grade = 'F';
        foreach (self::GRADE_THRESHOLDS as $g => $threshold) {
            if ($total >= $threshold) { $grade = $g; break; }
        }

        // ── Anomaly detection ─────────────────────────────────────────────
        $attempts = LmsQuestionnaireAttempt::where('enrollment_id', $enrollment->id)
            ->whereNotIn('status', ['in_progress'])
            ->get();

        $guessingDetected = $attempts->some(fn ($a) => $this->detectGuessingPatterns($a));
        $rushedDetected   = $this->detectRushedCompletion($enrollment);
        $lowEngagement    = $this->detectLowEngagement($enrollment);

        // ── Certificate blocking ──────────────────────────────────────────
        $passMark    = $enrollment->course->pass_score;
        $blockCert   = false;
        $blockReason = null;

        if ($passMark !== null && $total < $passMark) {
            $blockCert   = true;
            $blockReason = "Understanding score (" . round($total, 1) . "%) is below the required pass mark ({$passMark}%).";
        }
        if ($guessingDetected) {
            $blockCert   = true;
            $blockReason = trim(($blockReason ?? '') . ' Guessing pattern detected in assessment.');
        }

        $refresherRecommended = $total < 70 || $guessingDetected || $rushedDetected;

        // ── Upsert score record ───────────────────────────────────────────
        $score = LmsUnderstandingScore::updateOrCreate(
            ['enrollment_id' => $enrollment->id],
            [
                'user_id'                  => $enrollment->user_id,
                'course_id'                => $enrollment->course_id,
                'lesson_completion_score'  => round($lessonScore, 2),
                'quiz_score'               => round($quizScore, 2),
                'practical_score'          => round($practicalScore, 2),
                'time_score'               => round($timeScore, 2),
                'manager_score'            => $managerScore,
                'total_score'              => round($total, 2),
                'grade'                    => $grade,
                'guessing_detected'        => $guessingDetected,
                'rushed_detected'          => $rushedDetected,
                'low_engagement_detected'  => $lowEngagement,
                'is_certificate_blocked'   => $blockCert,
                'certificate_blocked_reason' => $blockReason,
                'refresher_recommended'    => $refresherRecommended,
                'manager_confirmed'        => $existing?->manager_confirmed ?? false,
                'manager_confirmed_by'     => $existing?->manager_confirmed_by,
                'manager_confirmed_at'     => $existing?->manager_confirmed_at,
                'calculated_at'            => now(),
            ]
        );

        // Detect and persist weak learning areas
        $this->detectWeakLearningAreas($enrollment);

        return $score;
    }

    // ── Weak area detection ───────────────────────────────────────────────────

    /**
     * Identify questions this learner failed more than once across all attempts
     * on this enrollment's course, and persist them as weak learning areas.
     */
    public function detectWeakLearningAreas(LmsEnrollment $enrollment): void
    {
        $failedByQuestion = LmsQuestionResponse::whereHas(
            'attempt',
            fn ($q) => $q->where('enrollment_id', $enrollment->id)
        )
        ->where('is_correct', false)
        ->with('question')
        ->get()
        ->groupBy('question_id');

        foreach ($failedByQuestion as $questionId => $responses) {
            $failCount = $responses->count();
            if ($failCount < 2) {
                continue;
            }

            $question   = $responses->first()->question;
            $latestFail = $responses->max('created_at');

            LmsWeakLearningArea::updateOrCreate(
                [
                    'user_id'    => $enrollment->user_id,
                    'question_id'=> $questionId,
                    'course_id'  => $enrollment->course_id,
                ],
                [
                    'questionnaire_id' => $question?->questionnaire_id,
                    'topic'            => $question ? mb_substr($question->question_text, 0, 200) : null,
                    'failure_count'    => $failCount,
                    'last_failed_at'   => $latestFail,
                    'is_resolved'      => false,
                ]
            );
        }
    }

    // ── Individual detection functions ────────────────────────────────────────

    /**
     * Returns true if the same question was answered incorrectly in ≥ 1 attempt.
     */
    public function detectRepeatedFailures(int $userId, int $questionnaireId): bool
    {
        return LmsQuestionResponse::whereHas(
            'attempt',
            fn ($q) => $q->where('user_id', $userId)->where('questionnaire_id', $questionnaireId)
        )
        ->where('is_correct', false)
        ->exists();
    }

    /**
     * Returns true when the average time per question is < 5 seconds,
     * which typically indicates rapid guessing rather than considered answering.
     */
    public function detectGuessingPatterns(LmsQuestionnaireAttempt $attempt): bool
    {
        if (!$attempt->started_at || !$attempt->completed_at) {
            return false;
        }

        $durationSecs  = $attempt->started_at->diffInSeconds($attempt->completed_at);
        $responseCount = $attempt->responses()->count();

        if ($responseCount === 0 || $durationSecs === 0) {
            return false;
        }

        return ($durationSecs / $responseCount) < self::GUESS_SECS_PER_Q;
    }

    /**
     * Returns true when actual time spent is < 25% of the course's stated duration.
     */
    public function detectRushedCompletion(LmsEnrollment $enrollment): bool
    {
        $expectedMins = $enrollment->course->duration_mins ?? 0;
        $actualMins   = $enrollment->time_spent_mins ?? 0;

        return $expectedMins > 0 && $actualMins < ($expectedMins * self::RUSHED_RATIO);
    }

    /**
     * Low engagement: no completed quiz attempts, or time spent < 10% of expected.
     */
    public function detectLowEngagement(LmsEnrollment $enrollment): bool
    {
        $hasAttempts = LmsQuestionnaireAttempt::where('enrollment_id', $enrollment->id)
            ->whereNotIn('status', ['in_progress'])
            ->exists();

        if (!$hasAttempts) {
            return true;
        }

        $expectedMins = $enrollment->course->duration_mins ?? 0;
        $actualMins   = $enrollment->time_spent_mins ?? 0;

        return $expectedMins > 0 && $actualMins < ($expectedMins * 0.10);
    }

    // ── Refresher recommendations ─────────────────────────────────────────────

    /**
     * Return all courses for a user that need a refresher, with the reasons why.
     */
    public function recommendRefresherTraining(int $userId): Collection
    {
        return LmsUnderstandingScore::where('user_id', $userId)
            ->where('refresher_recommended', true)
            ->with('course')
            ->get()
            ->map(fn ($s) => [
                'course_id'    => $s->course_id,
                'course_title' => $s->course->title,
                'score'        => $s->total_score,
                'grade'        => $s->grade,
                'reasons'      => collect([
                    $s->guessing_detected       ? 'Guessing pattern detected'           : null,
                    $s->rushed_detected         ? 'Training completed too quickly'      : null,
                    $s->low_engagement_detected ? 'Low engagement with course content'  : null,
                    $s->total_score < 70        ? "Low understanding score ({$s->total_score}%)" : null,
                ])->filter()->values()->all(),
            ]);
    }

    // ── Certificate gate ──────────────────────────────────────────────────────

    /**
     * Returns true when the certificate should be blocked for this score.
     */
    public function blockCertificateIfFailed(LmsUnderstandingScore $score): bool
    {
        return $score->is_certificate_blocked;
    }

    // ── Manager confirmation ──────────────────────────────────────────────────

    /**
     * Record manager confirmation, then recalculate to apply the 10% weight.
     */
    public function confirmManagerScore(LmsUnderstandingScore $score, int $managerId): LmsUnderstandingScore
    {
        $score->update([
            'manager_confirmed'    => true,
            'manager_confirmed_by' => $managerId,
            'manager_confirmed_at' => now(),
            'manager_score'        => 100,
        ]);

        return $this->calculateUnderstandingScore($score->enrollment);
    }
}
