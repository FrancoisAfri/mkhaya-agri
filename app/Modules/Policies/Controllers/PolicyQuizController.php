<?php

namespace App\Modules\Policies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyAttestation;
use App\Modules\Policies\Models\PolicyQuestion;
use App\Modules\Policies\Requests\SubmitQuizRequest;
use App\Modules\Policies\Services\PolicyQuizGeneratorService;
use App\Modules\Policies\Services\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PolicyQuizController extends Controller
{
    public function __construct(
        protected QuizService $quiz,
        protected PolicyQuizGeneratorService $generator,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN: Manage questions & settings for a specific policy
    // ─────────────────────────────────────────────────────────────────────────

    public function manage(Policy $policy): Response
    {
        $questions = $this->quiz->getQuestions($policy)->map(fn($q) => [
            'id'             => $q->id,
            'question_text'  => $q->question_text,
            'type'           => $q->type,
            'options'        => $q->options,
            'correct_answer' => $q->correct_answer,
            'explanation'    => $q->explanation,
            'sort_order'     => $q->sort_order,
            'points'         => $q->points,
            'ai_generated'   => $q->ai_generated,
        ]);

        $settings = $this->quiz->getOrCreateSettings($policy);
        $stats    = $this->quiz->adminStats($policy);

        return Inertia::render('Policies/Quiz/Manage', [
            'policy'    => [
                'id'   => $policy->id,
                'uuid' => $policy->uuid,
                'name' => $policy->name,
            ],
            'questions' => $questions,
            'settings'  => [
                'pass_mark'           => $settings->pass_mark,
                'max_attempts'        => $settings->max_attempts,
                'time_limit_minutes'  => $settings->time_limit_minutes,
                'randomize_questions' => $settings->randomize_questions,
                'show_correct_answers'=> $settings->show_correct_answers,
            ],
            'stats' => $stats,
        ]);
    }

    public function storeQuestion(Request $request, Policy $policy): RedirectResponse
    {
        $data = $request->validate([
            'question_text'  => ['required', 'string', 'max:2000'],
            'type'           => ['required', 'in:multiple_choice,true_false,short_answer'],
            'options'        => ['nullable', 'array'],
            'options.*.label'=> ['required_with:options', 'string', 'max:10'],
            'options.*.value'=> ['required_with:options', 'string', 'max:500'],
            'correct_answer' => ['required', 'string', 'max:500'],
            'explanation'    => ['nullable', 'string', 'max:1000'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'points'         => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $this->quiz->addQuestion($policy, array_merge($data, ['created_by' => Auth::id()]));

        return back()->with('success', 'Question added successfully.');
    }

    public function updateQuestion(Request $request, Policy $policy, PolicyQuestion $question): RedirectResponse
    {
        abort_unless($question->policy_id === $policy->id, 403);

        $data = $request->validate([
            'question_text'  => ['sometimes', 'required', 'string', 'max:2000'],
            'type'           => ['sometimes', 'required', 'in:multiple_choice,true_false,short_answer'],
            'options'        => ['nullable', 'array'],
            'options.*.label'=> ['required_with:options', 'string', 'max:10'],
            'options.*.value'=> ['required_with:options', 'string', 'max:500'],
            'correct_answer' => ['sometimes', 'required', 'string', 'max:500'],
            'explanation'    => ['nullable', 'string', 'max:1000'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'points'         => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $this->quiz->updateQuestion($question, $data);

        return back()->with('success', 'Question updated.');
    }

    public function destroyQuestion(Policy $policy, PolicyQuestion $question): RedirectResponse
    {
        abort_unless($question->policy_id === $policy->id, 403);

        $this->quiz->deleteQuestion($question);

        return back()->with('success', 'Question deleted.');
    }

    public function updateSettings(Request $request, Policy $policy): RedirectResponse
    {
        $data = $request->validate([
            'pass_mark'           => ['required', 'integer', 'min:0', 'max:100'],
            'max_attempts'        => ['required', 'integer', 'min:0', 'max:99'],
            'time_limit_minutes'  => ['nullable', 'integer', 'min:1', 'max:480'],
            'randomize_questions' => ['boolean'],
            'show_correct_answers'=> ['boolean'],
        ]);

        $this->quiz->updateSettings($policy, $data);

        return back()->with('success', 'Quiz settings saved.');
    }

    /**
     * AI-generate questions from the policy's extracted text.
     */
    public function generate(Request $request, Policy $policy): RedirectResponse
    {
        $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        try {
            $generated = $this->generator->generate($policy, (int) ($request->input('count', 10)));
            $count = count($generated);

            return back()->with('success', "{$count} question(s) generated by AI successfully.");
        } catch (\RuntimeException $e) {
            return back()->withErrors(['generate' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EMPLOYEE: Take quiz & view history
    // ─────────────────────────────────────────────────────────────────────────

    public function take(Policy $policy): Response | RedirectResponse
    {
        $user = Auth::user();

        if (! $this->quiz->canAttempt($policy, $user)) {
            return redirect()->route('policies.quiz.history', $policy->uuid)
                ->with('warning', 'You have used all your allowed attempts for this quiz.');
        }

        $questions  = $this->quiz->getQuestions($policy);
        $settings   = $this->quiz->getOrCreateSettings($policy);
        $attemptNum = $this->quiz->getAttemptCount($policy, $user) + 1;

        if ($questions->isEmpty()) {
            return redirect()->route('policies.index')
                ->with('info', 'This policy does not have a quiz configured yet.');
        }

        // Strip correct_answer so it's not exposed to the frontend
        $safeQuestions = $questions->map(fn($q) => [
            'id'           => $q->id,
            'question_text'=> $q->question_text,
            'type'         => $q->type,
            'options'      => $q->options,
            'points'       => $q->points,
            'sort_order'   => $q->sort_order,
        ]);

        return Inertia::render('Policies/Quiz/Take', [
            'policy' => [
                'id'   => $policy->id,
                'uuid' => $policy->uuid,
                'name' => $policy->name,
            ],
            'questions'   => $safeQuestions,
            'settings'    => [
                'pass_mark'          => $settings->pass_mark,
                'time_limit_minutes' => $settings->time_limit_minutes,
            ],
            'attempt_number' => $attemptNum,
        ]);
    }

    public function submit(SubmitQuizRequest $request, Policy $policy): RedirectResponse
    {
        $user = Auth::user();

        if (! $this->quiz->canAttempt($policy, $user)) {
            return back()->withErrors(['quiz' => 'You have used all your allowed attempts.']);
        }

        try {
            $attempt = $this->quiz->submitAttempt(
                $policy,
                $user,
                $request->input('answers', [])
            );

            return redirect()->route('policies.quiz.history', $policy->uuid)
                ->with($attempt->passed ? 'success' : 'warning',
                    $attempt->passed
                        ? "Congratulations! You passed with {$attempt->score}%."
                        : "You scored {$attempt->score}% — below the required pass mark. Please review and try again."
                );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['quiz' => $e->getMessage()]);
        }
    }

    public function history(Policy $policy): Response
    {
        $user     = Auth::user();
        $attestation = PolicyAttestation::query()
            ->where('policy_id', $policy->id)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $attempts = $this->quiz->getHistory($policy, $user)->map(fn($a) => [
            'id'                    => $a->id,
            'attempt_number'        => $a->attempt_number,
            'score'                 => $a->score,
            'passed'                => $a->passed,
            'total_questions'       => $a->total_questions,
            'correct_answers_count' => $a->correct_answers_count,
            'completed_at'          => $a->completed_at?->toDateTimeString(),
            'answers'               => $a->answers,
        ]);

        $settings   = $this->quiz->getOrCreateSettings($policy);
        $canRetake  = $this->quiz->canAttempt($policy, $user);
        $hasPassed  = $attempts->where('passed', true)->isNotEmpty();

        return Inertia::render('Policies/Quiz/History', [
            'policy' => [
                'id'   => $policy->id,
                'uuid' => $policy->uuid,
                'name' => $policy->name,
            ],
            'attempts'   => $attempts,
            'settings'   => [
                'pass_mark'           => $settings->pass_mark,
                'max_attempts'        => $settings->max_attempts,
                'show_correct_answers'=> $settings->show_correct_answers,
            ],
            'attestation_uuid' => $attestation?->uuid,
            'can_retake' => $canRetake,
            'has_passed' => $hasPassed,
        ]);
    }
}
