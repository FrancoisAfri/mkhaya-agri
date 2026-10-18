<?php

namespace App\Modules\Policies\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyAttestation;
use App\Modules\Policies\Models\PolicyQuestion;
use App\Modules\Policies\Models\PolicyQuizAttempt;
use App\Modules\Policies\Models\PolicyQuizSetting;
use App\Modules\Policies\Notifications\QuizFailedNotification;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class QuizService
{
    // ── Settings ───────────────────────────────────────────────────────────────

    public function getOrCreateSettings(Policy $policy): PolicyQuizSetting
    {
        return PolicyQuizSetting::firstOrCreate(
            ['policy_id' => $policy->id],
            [
                'pass_mark'            => 70,
                'max_attempts'         => 3,
                'time_limit_minutes'   => null,
                'randomize_questions'  => false,
                'show_correct_answers' => true,
            ]
        );
    }

    public function updateSettings(Policy $policy, array $data): PolicyQuizSetting
    {
        $settings = $this->getOrCreateSettings($policy);
        $settings->update($data);

        return $settings->fresh();
    }

    // ── Questions ──────────────────────────────────────────────────────────────

    public function getQuestions(Policy $policy): \Illuminate\Database\Eloquent\Collection
    {
        return PolicyQuestion::where('policy_id', $policy->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function addQuestion(Policy $policy, array $data): PolicyQuestion
    {
        $maxOrder = PolicyQuestion::where('policy_id', $policy->id)->max('sort_order') ?? 0;

        return PolicyQuestion::create([
            'policy_id'     => $policy->id,
            'question_text' => $data['question_text'],
            'type'          => $data['type'],
            'options'       => $data['options'] ?? null,
            'correct_answer'=> $data['correct_answer'],
            'explanation'   => $data['explanation'] ?? null,
            'sort_order'    => $data['sort_order'] ?? ($maxOrder + 1),
            'points'        => $data['points'] ?? 1,
            'ai_generated'  => $data['ai_generated'] ?? false,
            'created_by'    => $data['created_by'] ?? null,
        ]);
    }

    public function updateQuestion(PolicyQuestion $question, array $data): PolicyQuestion
    {
        $question->update($data);

        return $question->fresh();
    }

    public function deleteQuestion(PolicyQuestion $question): void
    {
        $question->delete();
    }

    // ── Attempt lifecycle ──────────────────────────────────────────────────────

    public function getAttemptCount(Policy $policy, User $user): int
    {
        return PolicyQuizAttempt::where('policy_id', $policy->id)
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->count();
    }

    public function canAttempt(Policy $policy, User $user): bool
    {
        $settings = $this->getOrCreateSettings($policy);

        if ($settings->max_attempts === 0) {
            return true; // unlimited
        }

        return $this->getAttemptCount($policy, $user) < $settings->max_attempts;
    }

    public function getLastPassedAttempt(Policy $policy, User $user): ?PolicyQuizAttempt
    {
        return PolicyQuizAttempt::where('policy_id', $policy->id)
            ->where('user_id', $user->id)
            ->where('passed', true)
            ->latest('completed_at')
            ->first();
    }

    /**
     * Grade a quiz submission, persist the attempt, and handle notifications.
     *
     * @param  array<int, string>  $answers  Keyed by question_id
     */
    public function submitAttempt(
        Policy $policy,
        User $user,
        array $answers,
        ?PolicyAttestation $attestation = null
    ): PolicyQuizAttempt {
        $settings  = $this->getOrCreateSettings($policy);
        $questions = $this->getQuestions($policy);

        if ($questions->isEmpty()) {
            throw new \RuntimeException('This policy has no quiz questions configured.');
        }

        $attemptNumber = $this->getAttemptCount($policy, $user) + 1;
        $graded        = [];
        $totalPoints   = 0;
        $earnedPoints  = 0;

        foreach ($questions as $q) {
            $given     = trim((string) ($answers[$q->id] ?? ''));
            $isCorrect = $this->isCorrect($q, $given);

            $totalPoints  += $q->points;
            $earnedPoints += $isCorrect ? $q->points : 0;

            $graded[] = [
                'question_id'    => $q->id,
                'question_text'  => $q->question_text,
                'given_answer'   => $given,
                'correct_answer' => $q->correct_answer,
                'is_correct'     => $isCorrect,
                'explanation'    => $settings->show_correct_answers ? $q->explanation : null,
            ];
        }

        $score  = $totalPoints > 0 ? (int) round(($earnedPoints / $totalPoints) * 100) : 0;
        $passed = $score >= $settings->pass_mark;

        $attempt = PolicyQuizAttempt::create([
            'policy_id'             => $policy->id,
            'user_id'               => $user->id,
            'attestation_id'        => $attestation?->id,
            'attempt_number'        => $attemptNumber,
            'total_questions'       => $questions->count(),
            'correct_answers_count' => collect($graded)->where('is_correct', true)->count(),
            'score'                 => $score,
            'passed'                => $passed,
            'answers'               => $graded,
            'started_at'            => now(),
            'completed_at'          => now(),
        ]);

        if (! $passed) {
            $this->notifyManagerOnFail($policy, $user, $attempt, $settings, $attemptNumber);
        }

        return $attempt;
    }

    public function getHistory(Policy $policy, User $user): \Illuminate\Database\Eloquent\Collection
    {
        return PolicyQuizAttempt::where('policy_id', $policy->id)
            ->where('user_id', $user->id)
            ->orderByDesc('attempt_number')
            ->get();
    }

    // ── Admin Stats ────────────────────────────────────────────────────────────

    public function adminStats(Policy $policy): array
    {
        $attempts = PolicyQuizAttempt::where('policy_id', $policy->id)
            ->whereNotNull('completed_at')
            ->get();

        $uniqueParticipants = $attempts->unique('user_id')->count();
        $passed = $attempts->where('passed', true)->count();
        $avgScore = $attempts->count() ? round($attempts->avg('score'), 1) : 0;

        return [
            'total_attempts'      => $attempts->count(),
            'unique_participants' => $uniqueParticipants,
            'pass_count'          => $passed,
            'fail_count'          => $attempts->where('passed', false)->count(),
            'pass_rate'           => $attempts->count() ? round($passed / $attempts->count() * 100, 1) : 0,
            'avg_score'           => $avgScore,
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function isCorrect(PolicyQuestion $question, string $given): bool
    {
        if ($question->type === 'short_answer') {
            // Case-insensitive substring match for short answers
            return stripos(trim($question->correct_answer), $given) !== false
                || stripos($given, trim($question->correct_answer)) !== false;
        }

        return strtolower(trim($given)) === strtolower(trim($question->correct_answer));
    }

    private function notifyManagerOnFail(
        Policy $policy,
        User $user,
        PolicyQuizAttempt $attempt,
        PolicyQuizSetting $settings,
        int $attemptNumber
    ): void {
        try {
            $person = HRPerson::where('user_id', $user->id)->first();

            if (! $person || ! $person->manager_id) {
                return;
            }

            $manager = HRPerson::with('user')->find($person->manager_id);

            if (! $manager?->user) {
                return;
            }

            $attemptsLeft = $settings->max_attempts === 0
                ? null
                : max(0, $settings->max_attempts - $attemptNumber);

            $manager->user->notify(new QuizFailedNotification($policy, $user, $attempt, $attemptsLeft));
        } catch (\Throwable $e) {
            Log::warning('QuizService: failed to notify manager after quiz fail', [
                'policy_id' => $policy->id,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
