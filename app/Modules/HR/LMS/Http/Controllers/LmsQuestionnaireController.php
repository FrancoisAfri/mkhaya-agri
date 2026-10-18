<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsQuestion;
use App\Modules\HR\LMS\Models\LmsQuestionnaire;
use App\Modules\HR\LMS\Models\LmsQuestionnaireAssignment;
use App\Modules\HR\LMS\Models\LmsQuestionnaireAttempt;
use App\Modules\HR\LMS\Models\LmsQuestionResponse;
use App\Modules\HR\LMS\Services\LmsPolicyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class LmsQuestionnaireController extends Controller
{
    public function __construct(private readonly LmsPolicyGuard $policyGuard) {}

    // ── Start / resume attempt ───────────────────────────────────────────────

    public function show(LmsQuestionnaire $questionnaire, Request $request): Response
    {
        $user           = Auth::user();
        $enrollmentId   = $request->query('enrollment_id');
        $assignmentId   = $request->query('assignment_id');

        // Check retake limits
        $previousAttempts = LmsQuestionnaireAttempt::where('questionnaire_id', $questionnaire->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['completed', 'passed', 'failed', 'pending_review'])
            ->count();

        $canAttempt = true;
        $retakeDeniedReason = null;

        if (!$questionnaire->allow_retake && $previousAttempts > 0) {
            $canAttempt = false;
            $retakeDeniedReason = 'This questionnaire does not allow retakes.';
        } elseif ($questionnaire->allow_retake && $questionnaire->max_retakes !== null && $previousAttempts >= $questionnaire->max_retakes) {
            $canAttempt = false;
            $retakeDeniedReason = "Maximum of {$questionnaire->max_retakes} attempt(s) reached.";
        }

        // Resume in-progress attempt if any
        $attempt = LmsQuestionnaireAttempt::where('questionnaire_id', $questionnaire->id)
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->with('responses')
            ->latest()
            ->first();

        $randomiseOptions = $questionnaire->randomise_options;

        $questions = $questionnaire->questions()
            ->with('options')
            ->when($questionnaire->randomise_questions, fn ($q) => $q->inRandomOrder())
            ->get()
            ->map(fn ($q) => [
                'id'            => $q->id,
                'uuid'          => $q->uuid,
                'type'          => $q->type,
                'question_text' => $q->question_text,
                'context_text'  => $q->context_text,
                'points'        => $q->points,
                'has_options'   => $q->hasOptions(),
                'options'       => ($randomiseOptions ? $q->options->shuffle() : $q->options)->map(fn ($o) => [
                    'id'           => $o->id,
                    'option_text'  => $o->option_text,
                    'rating_value' => $o->rating_value,
                    'sort_order'   => $o->sort_order,
                ]),
            ]);

        // Map any saved responses for resume
        $savedResponses = [];
        if ($attempt) {
            foreach ($attempt->responses as $r) {
                $savedResponses[$r->question_id] = [
                    'response_text' => $r->response_text,
                    'response_json' => $r->response_json,
                    'file_name'     => $r->file_name,
                ];
            }
        }

        $lastAttempt = LmsQuestionnaireAttempt::where('questionnaire_id', $questionnaire->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['completed', 'passed', 'failed', 'pending_review'])
            ->latest()
            ->first();

        return Inertia::render('Modules/LMS/Questionnaire', [
            'questionnaire' => [
                'id'                  => $questionnaire->id,
                'title'               => $questionnaire->title,
                'description'         => $questionnaire->description,
                'instructions'        => $questionnaire->instructions,
                'type'                => $questionnaire->type,
                'time_limit_mins'     => $questionnaire->time_limit_mins,
                'pass_score'          => $questionnaire->pass_score,
                'show_answers_after'  => $questionnaire->show_answers_after,
            ],
            'questions'          => $questions,
            'attemptId'          => $attempt?->id,
            'savedResponses'     => $savedResponses,
            'canAttempt'         => $canAttempt,
            'retakeDeniedReason' => $retakeDeniedReason,
            'previousAttempts'   => $previousAttempts,
            'lastAttempt'        => $lastAttempt ? [
                'id'     => $lastAttempt->id,
                'status' => $lastAttempt->status,
                'score'  => $lastAttempt->score,
            ] : null,
            'enrollmentId'  => $enrollmentId ? (int) $enrollmentId : null,
            'assignmentId'  => $assignmentId ? (int) $assignmentId : null,
        ]);
    }

    // ── Start a fresh attempt ────────────────────────────────────────────────

    public function start(Request $request, LmsQuestionnaire $questionnaire): JsonResponse
    {
        $user = Auth::user();

        $previousAttempts = LmsQuestionnaireAttempt::where('questionnaire_id', $questionnaire->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['completed', 'passed', 'failed', 'pending_review'])
            ->count();

        if (!$questionnaire->allow_retake && $previousAttempts > 0) {
            return response()->json(['error' => 'Retakes not allowed.'], 422);
        }

        if ($questionnaire->allow_retake && $questionnaire->max_retakes !== null && $previousAttempts >= $questionnaire->max_retakes) {
            return response()->json(['error' => 'Maximum retakes reached.'], 422);
        }

        // Close any stale in-progress attempt
        LmsQuestionnaireAttempt::where('questionnaire_id', $questionnaire->id)
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->update(['status' => 'failed', 'completed_at' => now()]);

        $attempt = LmsQuestionnaireAttempt::create([
            'questionnaire_id' => $questionnaire->id,
            'user_id'          => $user->id,
            'enrollment_id'    => $request->enrollment_id,
            'assignment_id'    => $request->assignment_id,
            'status'           => 'in_progress',
            'attempt_number'   => $previousAttempts + 1,
            'started_at'       => now(),
        ]);

        return response()->json(['attempt_id' => $attempt->id]);
    }

    // ── Save a single response (auto-save) ───────────────────────────────────

    public function saveResponse(Request $request, LmsQuestionnaire $questionnaire, LmsQuestionnaireAttempt $attempt): JsonResponse
    {
        abort_unless($attempt->questionnaire_id === $questionnaire->id && $attempt->user_id === Auth::id(), 403);
        abort_unless($attempt->status === 'in_progress', 422);

        $request->validate([
            'question_id'   => ['required', 'integer', 'exists:lms_questions,id'],
            'response_text' => ['nullable', 'string'],
            'response_json' => ['nullable', 'array'],
            'file'          => ['nullable', 'file', 'max:51200'], // 50 MB for file uploads
        ]);

        $data = [
            'attempt_id'    => $attempt->id,
            'question_id'   => $request->question_id,
            'response_text' => $request->response_text,
            'response_json' => $request->response_json,
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $dir  = "lms/responses/{$attempt->id}";
            $path = Storage::disk('local')->putFile($dir, $file);

            $data['file_path'] = $path;
            $data['file_disk'] = 'local';
            $data['file_name'] = $file->getClientOriginalName();
        }

        LmsQuestionResponse::updateOrCreate(
            ['attempt_id' => $attempt->id, 'question_id' => $request->question_id],
            $data
        );

        return response()->json(['saved' => true]);
    }

    // ── Submit attempt ───────────────────────────────────────────────────────

    public function submit(Request $request, LmsQuestionnaire $questionnaire, LmsQuestionnaireAttempt $attempt): RedirectResponse
    {
        abort_unless($attempt->questionnaire_id === $questionnaire->id && $attempt->user_id === Auth::id(), 403);
        abort_unless($attempt->status === 'in_progress', 422);

        // Persist any final responses sent with the submit payload
        foreach ($request->input('responses', []) as $questionId => $responseData) {
            $question = LmsQuestion::find($questionId);
            if (!$question) {
                continue;
            }

            $updateData = [
                'attempt_id'    => $attempt->id,
                'question_id'   => $questionId,
                'response_text' => $responseData['response_text'] ?? null,
                'response_json' => $responseData['response_json'] ?? null,
            ];

            LmsQuestionResponse::updateOrCreate(
                ['attempt_id' => $attempt->id, 'question_id' => $questionId],
                $updateData
            );
        }

        // Auto-grade and update status
        $attempt->autoGrade();
        $attempt->refresh();

        // Policy check: validate submission against pass mark and assessment policies
        $this->policyGuard->forAssessmentSubmission($attempt, Auth::id());

        return redirect()->route('lms.questionnaires.result', [
            'questionnaire' => $questionnaire->id,
            'attempt'       => $attempt->id,
        ]);
    }

    // ── View result ─────────────────────────────────────────────────────────

    public function result(LmsQuestionnaire $questionnaire, LmsQuestionnaireAttempt $attempt): Response
    {
        abort_unless($attempt->questionnaire_id === $questionnaire->id, 404);
        abort_unless($attempt->user_id === Auth::id() || Auth::user()?->isAdmin(), 403);

        $attempt->load('responses.question.options');
        $questionnaire->load('questions.options');

        $questionsWithAnswers = $questionnaire->questions->map(function ($question) use ($attempt, $questionnaire) {
            $response = $attempt->responses->firstWhere('question_id', $question->id);
            return [
                'id'             => $question->id,
                'type'           => $question->type,
                'question_text'  => $question->question_text,
                'explanation'    => $questionnaire->show_answers_after ? $question->explanation : null,
                'points'         => $question->points,
                'is_auto_graded' => $question->isAutoGraded(),
                'options'        => $question->options->map(fn ($o) => [
                    'id'          => $o->id,
                    'option_text' => $o->option_text,
                    'is_correct'  => $questionnaire->show_answers_after ? $o->is_correct : null,
                ]),
                'response' => $response ? [
                    'response_text'  => $response->response_text,
                    'response_json'  => $response->response_json,
                    'file_name'      => $response->file_name,
                    'is_correct'     => $response->is_correct,
                    'points_awarded' => $response->points_awarded,
                    'reviewer_notes' => $response->reviewer_notes,
                ] : null,
            ];
        });

        return Inertia::render('Modules/LMS/QuestionnaireResult', [
            'questionnaire' => [
                'id'                 => $questionnaire->id,
                'title'              => $questionnaire->title,
                'pass_score'         => $questionnaire->pass_score,
                'show_answers_after' => $questionnaire->show_answers_after,
            ],
            'attempt' => [
                'id'           => $attempt->id,
                'status'       => $attempt->status,
                'score'        => $attempt->score,
                'attempt_number'=> $attempt->attempt_number,
                'started_at'   => $attempt->started_at,
                'completed_at' => $attempt->completed_at,
            ],
            'questions' => $questionsWithAnswers,
        ]);
    }

    // ── Download submitted file (learner or admin) ───────────────────────────

    public function downloadFile(
        LmsQuestionnaire $questionnaire,
        LmsQuestionnaireAttempt $attempt,
        LmsQuestionResponse $response
    ) {
        abort_unless($attempt->questionnaire_id === $questionnaire->id, 404);
        abort_unless($response->attempt_id === $attempt->id, 404);
        abort_unless(!empty($response->file_path) && !empty($response->file_disk), 404);

        return Storage::disk($response->file_disk)->download(
            $response->file_path,
            $response->file_name ?? 'download'
        );
    }
}
