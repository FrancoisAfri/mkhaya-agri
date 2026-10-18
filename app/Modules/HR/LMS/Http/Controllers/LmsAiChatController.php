<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsAiArtefact;
use App\Modules\HR\LMS\Models\LmsAiSession;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Services\LmsAiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LmsAiChatController extends Controller
{
    public function __construct(private readonly LmsAiChatService $service) {}

    // ── Employee-facing chat page ─────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $userId   = Auth::id();
        $sessions = $this->service->getSessions($userId);
        $courses  = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);

        return Inertia::render('Modules/LMS/AiTrainingChat', [
            'sessions'        => $sessions,
            'courses'         => $courses,
            'persona'         => 'employee',
            'allowedPersonas' => $this->allowedPersonas(),
            'sessionId'       => $request->query('session'),
            'history'         => $request->filled('session')
                ? $this->service->getHistory($request->query('session'))
                : [],
        ]);
    }

    // ── Manager compliance chat page ──────────────────────────────────────────

    public function managerChat(Request $request): Response
    {
        $userId   = Auth::id();
        $sessions = $this->service->getSessions($userId, 'compliance');
        $courses  = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);

        return Inertia::render('Modules/LMS/AiTrainingChat', [
            'sessions'        => $sessions,
            'courses'         => $courses,
            'persona'         => 'manager',
            'allowedPersonas' => $this->allowedPersonas(),
            'sessionId'       => $request->query('session'),
            'history'         => $request->filled('session')
                ? $this->service->getHistory($request->query('session'))
                : [],
        ]);
    }

    // ── HR skills-gap chat page ───────────────────────────────────────────────

    public function hrChat(Request $request): Response
    {
        $userId   = Auth::id();
        $sessions = $this->service->getSessions($userId, 'skills_gap');
        $courses  = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);

        return Inertia::render('Modules/LMS/AiTrainingChat', [
            'sessions'        => $sessions,
            'courses'         => $courses,
            'persona'         => 'hr_admin',
            'allowedPersonas' => $this->allowedPersonas(),
            'sessionId'       => $request->query('session'),
            'history'         => $request->filled('session')
                ? $this->service->getHistory($request->query('session'))
                : [],
        ]);
    }

    // ── Allowed personas for the authenticated user ───────────────────────────

    private function allowedPersonas(): array
    {
        $user     = Auth::user();
        $personas = ['employee'];

        if ($user->hasRole(['manager', 'senior manager', 'team leader', 'executive', 'administrator', 'super admin'])) {
            $personas[] = 'manager';
        }

        if ($user->hasRole(['HR', 'HR manager', 'administrator', 'super admin'])) {
            $personas[] = 'hr_admin';
        }

        return $personas;
    }

    // ── General / course Q&A (JSON API) ──────────────────────────────────────

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question'   => 'required|string|max:4000',
            'session_id' => 'nullable|string|max:100',
            'course_id'  => 'nullable|exists:lms_courses,id',
            'lesson_id'  => 'nullable|exists:lms_lessons,id',
            'persona'    => 'nullable|in:employee,manager,hr_admin',
        ]);

        $sessionId = $data['session_id'] ?? Str::uuid()->toString();
        $userId    = Auth::id();

        $result = $this->service->askTrainingAI(
            question:  $data['question'],
            userId:    $userId,
            sessionId: $sessionId,
            courseId:  $data['course_id'] ?? null,
            lessonId:  $data['lesson_id'] ?? null,
            persona:   $data['persona'] ?? 'employee',
        );

        return response()->json([
            'answer'     => $result['text'],
            'session_id' => $sessionId,
            'tokens'     => $result['tokens'],
        ]);
    }

    // ── Policy explanation (JSON API) ─────────────────────────────────────────

    public function policyChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question'           => 'required|string|max:4000',
            'policy_document_id' => 'required|exists:policy_documents,id',
            'session_id'         => 'nullable|string|max:100',
            'persona'            => 'nullable|in:employee,manager,hr_admin',
        ]);

        $sessionId = $data['session_id'] ?? Str::uuid()->toString();
        $userId    = Auth::id();

        $result = $this->service->explainPolicyTraining(
            policyDocumentId: $data['policy_document_id'],
            question:         $data['question'],
            userId:           $userId,
            sessionId:        $sessionId,
            persona:          $data['persona'] ?? 'employee',
        );

        return response()->json([
            'answer'     => $result['text'],
            'session_id' => $sessionId,
            'tokens'     => $result['tokens'],
        ]);
    }

    // ── Compliance Q&A (JSON API) ─────────────────────────────────────────────

    public function complianceChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question'       => 'required|string|max:4000',
            'session_id'     => 'nullable|string|max:100',
            'persona'        => 'nullable|in:manager,hr_admin',
            'target_user_id' => 'nullable|exists:users,id',
        ]);

        $sessionId = $data['session_id'] ?? Str::uuid()->toString();
        $userId    = Auth::id();

        $result = $this->service->answerTrainingComplianceQuestion(
            question:     $data['question'],
            userId:       $userId,
            sessionId:    $sessionId,
            persona:      $data['persona'] ?? 'manager',
            targetUserId: $data['target_user_id'] ?? null,
        );

        return response()->json([
            'answer'     => $result['text'],
            'session_id' => $sessionId,
            'tokens'     => $result['tokens'],
        ]);
    }

    // ── Skills gap Q&A (JSON API) ─────────────────────────────────────────────

    public function skillsGapChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question'       => 'required|string|max:4000',
            'session_id'     => 'nullable|string|max:100',
            'target_user_id' => 'nullable|exists:users,id',
            'department_id'  => 'nullable|exists:company_units,id',
        ]);

        $sessionId = $data['session_id'] ?? Str::uuid()->toString();
        $userId    = Auth::id();

        $result = $this->service->answerSkillsGapQuestion(
            question:     $data['question'],
            userId:       $userId,
            sessionId:    $sessionId,
            targetUserId: $data['target_user_id'] ?? null,
            departmentId: $data['department_id']  ?? null,
        );

        return response()->json([
            'answer'     => $result['text'],
            'session_id' => $sessionId,
            'tokens'     => $result['tokens'],
        ]);
    }

    // ── Generated artefacts ───────────────────────────────────────────────────

    public function summarise(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id' => 'required|exists:lms_courses,id',
            'lesson_id' => 'nullable|exists:lms_lessons,id',
        ]);

        $artefact = $this->service->summarizeCourseContent(
            courseId: $data['course_id'],
            userId:   Auth::id(),
            lessonId: $data['lesson_id'] ?? null,
        );

        return response()->json([
            'artefact_id' => $artefact->id,
            'title'       => $artefact->title,
            'content'     => $artefact->content,
        ]);
    }

    public function studyGuide(Request $request): JsonResponse
    {
        $data = $request->validate(['course_id' => 'required|exists:lms_courses,id']);

        $artefact = $this->service->generateStudyGuide(
            courseId: $data['course_id'],
            userId:   Auth::id(),
        );

        return response()->json([
            'artefact_id' => $artefact->id,
            'title'       => $artefact->title,
            'content'     => $artefact->content,
        ]);
    }

    public function practiceQuiz(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id' => 'required|exists:lms_courses,id',
            'count'     => 'nullable|integer|min:3|max:20',
        ]);

        $artefact = $this->service->generatePracticeQuiz(
            courseId: $data['course_id'],
            userId:   Auth::id(),
            count:    $data['count'] ?? 5,
        );

        return response()->json([
            'artefact_id' => $artefact->id,
            'title'       => $artefact->title,
            'questions'   => $artefact->structured_data ?? [],
        ]);
    }

    // ── History / sessions ────────────────────────────────────────────────────

    public function history(Request $request): JsonResponse
    {
        $request->validate(['session_id' => 'required|string']);
        return response()->json($this->service->getHistory($request->session_id));
    }

    public function sessions(Request $request): JsonResponse
    {
        $type = $request->query('context_type', 'all');
        return response()->json($this->service->getSessions(Auth::id(), $type));
    }

    public function archiveSession(Request $request): JsonResponse
    {
        $request->validate(['session_id' => 'required|string']);
        $this->service->archiveSession($request->session_id, Auth::id());
        return response()->json(['ok' => true]);
    }

    // ── Artefacts library ─────────────────────────────────────────────────────

    public function artefacts(Request $request): JsonResponse
    {
        $type = $request->query('type', 'all');
        return response()->json($this->service->getArtefacts(Auth::id(), $type));
    }

    public function destroyArtefact(LmsAiArtefact $artefact): JsonResponse
    {
        if ($artefact->user_id !== Auth::id()) {
            abort(403);
        }
        $artefact->delete();
        return response()->json(['ok' => true]);
    }
}
