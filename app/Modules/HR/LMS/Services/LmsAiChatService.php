<?php

namespace App\Modules\HR\LMS\Services;

use App\Core\AI\Base\BaseModuleAI;
use App\Core\AI\Registry\AgentRegistry;
use App\Core\AI\Models\AiChatMessage;
use App\Core\AI\Models\AiChatSession;
use App\Modules\HR\LMS\Models\LmsAiArtefact;
use App\Modules\HR\LMS\Models\LmsAiSession;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsComplianceRule;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEmployeeSkill;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsLesson;
use App\Modules\Policies\Models\PolicyClause;
use App\Modules\Policies\Models\PolicyDocument;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LmsAiChatService extends BaseModuleAI
{
    protected string $module = 'lms';

    private int $maxTokens    = 2048;
    private int $historyLimit = 12;

    public function __construct(AgentRegistry $registry)
    {
        parent::__construct($registry);
    }

    // ── askTrainingAI ──────────────────────────────────────────────────────────

    public function askTrainingAI(
        string  $question,
        int     $userId,
        string  $sessionId,
        ?int    $courseId    = null,
        ?int    $lessonId    = null,
        string  $persona     = 'employee'
    ): array {
        $this->rateCheck($userId);

        $context   = $this->buildCourseContext($courseId, $lessonId, $userId);
        $system    = $this->buildSystemPrompt('course_qa', $persona, $context);
        $history   = $this->loadHistory($sessionId);
        $response  = $this->call($system, $history, $question);

        $this->persistTurn($userId, $sessionId, $question, $response['text'], $response['tokens']);
        $this->ensureLmsSession($userId, $sessionId, 'course', $courseId, $persona, $question);

        return $response;
    }

    // ── summarizeCourseContent ─────────────────────────────────────────────────

    public function summarizeCourseContent(int $courseId, int $userId, ?int $lessonId = null): LmsAiArtefact
    {
        $this->rateCheck($userId);

        $context   = $this->buildCourseContext($courseId, $lessonId, $userId);
        $system    = $this->buildSystemPrompt('summary', 'employee', $context);
        $scope     = $lessonId ? 'this lesson' : 'this entire course';
        $prompt    = "Please provide a clear, concise summary of {$scope}. "
                   . "Structure it with: Key Topics, Main Learning Points, and Key Takeaways.";

        $result    = $this->call($system, [], $prompt);
        $title     = $lessonId
            ? 'Summary: ' . (LmsLesson::find($lessonId)?->title ?? 'Lesson')
            : 'Summary: ' . (LmsCourse::find($courseId)?->title ?? 'Course');

        return LmsAiArtefact::create([
            'user_id'       => $userId,
            'artefact_type' => 'summary',
            'context_type'  => $lessonId ? 'lesson' : 'course',
            'context_id'    => $lessonId ?? $courseId,
            'title'         => $title,
            'content'       => $result['text'],
            'tokens_used'   => $result['tokens'],
        ]);
    }

    // ── explainPolicyTraining ──────────────────────────────────────────────────

    public function explainPolicyTraining(
        int     $policyDocumentId,
        string  $question,
        int     $userId,
        string  $sessionId,
        string  $persona = 'employee'
    ): array {
        $this->rateCheck($userId);

        $doc     = PolicyDocument::find($policyDocumentId);
        $clauses = PolicyClause::where('document_id', $policyDocumentId)
            ->orderBy('clause_reference')
            ->limit(40)
            ->get(['clause_reference', 'type', 'content'])
            ->map(fn ($c) => "[{$c->clause_reference}] {$c->content}")
            ->implode("\n\n");

        $docText  = $doc?->extracted_text ?? '';
        $combined = $clauses ?: Str::limit($docText, 6000);

        $system   = $this->buildSystemPrompt('policy_qa', $persona, [
            'document_name' => $doc?->filename ?? 'Policy Document',
            'policy_text'   => $combined,
        ]);
        $history  = $this->loadHistory($sessionId);
        $response = $this->call($system, $history, $question);

        $this->persistTurn($userId, $sessionId, $question, $response['text'], $response['tokens']);
        $this->ensureLmsSession($userId, $sessionId, 'policy', $policyDocumentId, $persona, $question);

        return $response;
    }

    // ── generateStudyGuide ────────────────────────────────────────────────────

    public function generateStudyGuide(int $courseId, int $userId): LmsAiArtefact
    {
        $this->rateCheck($userId);

        $context = $this->buildCourseContext($courseId, null, $userId);
        $system  = $this->buildSystemPrompt('study_guide', 'employee', $context);
        $prompt  = "Generate a comprehensive study guide for this course. "
                 . "Include: 1) Learning Objectives, 2) Key Concepts with brief explanations, "
                 . "3) Important Definitions, 4) Common Exam/Assessment Areas, "
                 . "5) Tips for remembering difficult concepts, 6) A self-check checklist.";

        $result  = $this->call($system, [], $prompt, 3000);
        $course  = LmsCourse::find($courseId);

        return LmsAiArtefact::create([
            'user_id'       => $userId,
            'artefact_type' => 'study_guide',
            'context_type'  => 'course',
            'context_id'    => $courseId,
            'title'         => 'Study Guide: ' . ($course?->title ?? 'Course'),
            'content'       => $result['text'],
            'tokens_used'   => $result['tokens'],
        ]);
    }

    // ── generatePracticeQuiz ──────────────────────────────────────────────────

    public function generatePracticeQuiz(int $courseId, int $userId, int $count = 5): LmsAiArtefact
    {
        $this->rateCheck($userId);

        $context = $this->buildCourseContext($courseId, null, $userId);
        $system  = $this->buildSystemPrompt('practice_quiz', 'employee', $context);
        $prompt  = "Generate exactly {$count} multiple-choice practice questions for this course. "
                 . "Return ONLY a JSON array. Each item must have: "
                 . "\"question\" (string), \"options\" (array of 4 strings), "
                 . "\"answer\" (0-based index of correct option), \"explanation\" (string). "
                 . "Cover the most important topics from the course content.";

        $result  = $this->callJson($system, [], $prompt, 2500);
        $course  = LmsCourse::find($courseId);
        $items   = is_array($result['data']) ? $result['data'] : ($result['data']['questions'] ?? []);

        return LmsAiArtefact::create([
            'user_id'         => $userId,
            'artefact_type'   => 'practice_quiz',
            'context_type'    => 'course',
            'context_id'      => $courseId,
            'title'           => "Practice Quiz: " . ($course?->title ?? 'Course') . " ({$count} questions)",
            'content'         => "Practice quiz with {$count} questions.",
            'structured_data' => $items,
            'tokens_used'     => $result['tokens'],
        ]);
    }

    // ── answerTrainingComplianceQuestion ──────────────────────────────────────

    public function answerTrainingComplianceQuestion(
        string  $question,
        int     $userId,
        string  $sessionId,
        string  $persona = 'manager',
        ?int    $targetUserId = null
    ): array {
        $this->rateCheck($userId);

        $context = $this->buildComplianceContext($userId, $persona, $targetUserId);
        $system  = $this->buildSystemPrompt('compliance_qa', $persona, $context);
        $history = $this->loadHistory($sessionId);
        $response = $this->call($system, $history, $question);

        $this->persistTurn($userId, $sessionId, $question, $response['text'], $response['tokens']);
        $this->ensureLmsSession($userId, $sessionId, 'compliance', null, $persona, $question);

        return $response;
    }

    // ── answerSkillsGapQuestion (HR) ──────────────────────────────────────────

    public function answerSkillsGapQuestion(
        string  $question,
        int     $userId,
        string  $sessionId,
        ?int    $targetUserId = null,
        ?int    $departmentId = null
    ): array {
        $this->rateCheck($userId);

        $context  = $this->buildSkillsGapContext($targetUserId, $departmentId);
        $system   = $this->buildSystemPrompt('skills_gap', 'hr_admin', $context);
        $history  = $this->loadHistory($sessionId);
        $response = $this->call($system, $history, $question);

        $this->persistTurn($userId, $sessionId, $question, $response['text'], $response['tokens']);
        $this->ensureLmsSession($userId, $sessionId, 'skills_gap', $targetUserId, 'hr_admin', $question);

        return $response;
    }

    // ── Session helpers ───────────────────────────────────────────────────────

    public function getHistory(string $sessionId): array
    {
        return AiChatMessage::where('session_id', $sessionId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get(['role', 'content', 'created_at'])
            ->map(fn ($m) => [
                'role'       => $m->role,
                'content'    => $m->content,
                'created_at' => $m->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    public function getSessions(int $userId, string $contextType = 'all'): array
    {
        $query = LmsAiSession::where('user_id', $userId)
            ->where('status', 'active')
            ->orderByDesc('updated_at');

        if ($contextType !== 'all') {
            $query->where('context_type', $contextType);
        }

        return $query->limit(30)->get()->map(fn ($s) => [
            'session_id'   => $s->session_id,
            'title'        => $s->title,
            'context_type' => $s->context_type,
            'context_id'   => $s->context_id,
            'persona'      => $s->persona,
            'updated_at'   => $s->updated_at?->toDateTimeString(),
        ])->all();
    }

    public function archiveSession(string $sessionId, int $userId): void
    {
        LmsAiSession::where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->update(['status' => 'archived']);
    }

    public function getArtefacts(int $userId, string $type = 'all'): array
    {
        $query = LmsAiArtefact::where('user_id', $userId)->orderByDesc('created_at');

        if ($type !== 'all') {
            $query->where('artefact_type', $type);
        }

        return $query->limit(50)->get()->map(fn ($a) => [
            'id'             => $a->id,
            'artefact_type'  => $a->artefact_type,
            'type_label'     => $a->typeLabel(),
            'context_type'   => $a->context_type,
            'context_id'     => $a->context_id,
            'title'          => $a->title,
            'content'        => $a->content,
            'structured_data'=> $a->structured_data,
            'tokens_used'    => $a->tokens_used,
            'created_at'     => $a->created_at?->toDateTimeString(),
        ])->all();
    }

    // ── Context builders ──────────────────────────────────────────────────────

    private function buildCourseContext(?int $courseId, ?int $lessonId, int $userId): array
    {
        $ctx = [];

        if ($courseId) {
            $course = LmsCourse::with(['lessons' => fn ($q) => $q->orderBy('order')->limit(30)])
                ->find($courseId, ['id', 'title', 'description']);

            if ($course) {
                $ctx['course_title']       = $course->title;
                $ctx['course_description'] = Str::limit($course->description, 400);
                $ctx['lessons']            = $course->lessons
                    ->map(fn ($l) => ['title' => $l->title, 'type' => $l->type])
                    ->all();
            }
        }

        if ($lessonId) {
            $lesson = LmsLesson::find($lessonId, ['id', 'title', 'type', 'content_body']);
            if ($lesson) {
                $ctx['lesson_title']   = $lesson->title;
                $ctx['lesson_content'] = Str::limit(strip_tags($lesson->content_body ?? ''), 4000);
            }
        }

        if ($courseId) {
            $enrollment = LmsEnrollment::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first(['status', 'score', 'progress']);
            if ($enrollment) {
                $ctx['learner_status']   = $enrollment->status;
                $ctx['learner_score']    = $enrollment->score;
                $ctx['learner_progress'] = $enrollment->progress;
            }
        }

        return $ctx;
    }

    private function buildComplianceContext(int $_userId, string $persona, ?int $targetUserId): array
    {
        $ctx = [];

        if ($persona === 'manager') {
            $overdue = LmsComplianceAssignment::with(['user.person', 'rule', 'course'])
                ->where('status', 'overdue')
                ->limit(20)
                ->get()
                ->map(fn ($a) => [
                    'employee'   => $a->user?->person
                        ? $a->user->person->first_name . ' ' . $a->user->person->surname
                        : 'Unknown',
                    'course'     => $a->course?->title,
                    'rule'       => $a->rule?->name,
                    'due_date'   => $a->due_date?->toDateString(),
                ]);
            $ctx['overdue_assignments'] = $overdue->all();
        }

        if ($targetUserId) {
            $assignments = LmsComplianceAssignment::with(['rule', 'course'])
                ->where('user_id', $targetUserId)
                ->orderByDesc('assigned_at')
                ->limit(15)
                ->get()
                ->map(fn ($a) => [
                    'rule'   => $a->rule?->name,
                    'course' => $a->course?->title,
                    'status' => $a->status,
                    'due'    => $a->due_date?->toDateString(),
                ]);
            $ctx['employee_assignments'] = $assignments->all();
        }

        $rules = LmsComplianceRule::where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get(['name', 'scope', 'mandatory'])
            ->map(fn ($r) => "{$r->name} (scope: {$r->scope}, mandatory: " . ($r->mandatory ? 'yes' : 'no') . ")")
            ->implode('; ');
        $ctx['active_rules'] = $rules;

        return $ctx;
    }

    private function buildSkillsGapContext(?int $targetUserId, ?int $departmentId): array
    {
        $ctx = [];

        if ($targetUserId) {
            $gaps = LmsEmployeeSkill::where('user_id', $targetUserId)
                ->with('skill')
                ->get()
                ->map(fn ($es) => [
                    'skill'         => $es->skill?->name ?? $es->skill_id,
                    'current_level' => $es->current_level,
                    'required_level'=> $es->skill?->required_level ?? null,
                    'gap'           => ($es->skill?->required_level ?? 0) - $es->current_level,
                ])
                ->filter(fn ($g) => $g['gap'] > 0)
                ->values()
                ->all();

            $ctx['skills_gaps'] = $gaps;
        }

        if ($departmentId) {
            $deptGaps = LmsEmployeeSkill::with('skill')
                ->whereHas('user', fn ($q) => $q->whereHas(
                    'companyUnitUsers',
                    fn ($cq) => $cq->where('company_unit_id', $departmentId)
                ))
                ->get()
                ->groupBy('skill_id')
                ->map(fn ($items, $skillId) => [
                    'skill'      => $items->first()?->skill?->name ?? $skillId,
                    'avg_level'  => round($items->avg('current_level'), 1),
                    'count'      => $items->count(),
                ])
                ->values()
                ->all();

            $ctx['department_skills'] = $deptGaps;
        }

        return $ctx;
    }

    // ── System prompts ────────────────────────────────────────────────────────

    protected function buildSystemPrompt(string $mode = '', string $persona = '', array $context = []): string
    {
        $base = match ($persona) {
            'manager'  => 'You are an expert Learning & Development AI assistant helping a manager.',
            'hr_admin' => 'You are an expert L&D AI assistant helping an HR professional.',
            default    => 'You are a friendly, knowledgeable Learning & Development AI assistant helping an employee.',
        };

        $contextBlock = '';

        switch ($mode) {
            case 'course_qa':
                $title       = $context['course_title']       ?? '';
                $desc        = $context['course_description'] ?? '';
                $lessonTitle = $context['lesson_title']        ?? '';
                $lessonBody  = $context['lesson_content']      ?? '';
                $progress    = $context['learner_progress']    ?? '';

                $contextBlock  = $title ? "COURSE: {$title}\nDESCRIPTION: {$desc}\n" : '';
                $contextBlock .= $lessonTitle ? "\nCURRENT LESSON: {$lessonTitle}\nCONTENT:\n{$lessonBody}\n" : '';
                $contextBlock .= $progress ? "\nLEARNER PROGRESS: {$progress}%\n" : '';

                return "{$base}\nYour role: Answer questions about course content accurately and educationally.\n"
                     . "Ground answers in the content below. If something is not in the material, say so.\n\n"
                     . $contextBlock;

            case 'policy_qa':
                $docName  = $context['document_name'] ?? 'Policy Document';
                $docText  = $context['policy_text']   ?? '';

                return "{$base}\nYour role: Explain policy documents in plain language for training purposes.\n"
                     . "Always cite the relevant clause when answering. Be accurate and never guess.\n\n"
                     . "DOCUMENT: {$docName}\n\n{$docText}";

            case 'compliance_qa':
                $rules   = $context['active_rules']          ?? '';
                $overdue = json_encode($context['overdue_assignments'] ?? [], JSON_PRETTY_PRINT);
                $empAsgn = json_encode($context['employee_assignments'] ?? [], JSON_PRETTY_PRINT);

                return "{$base}\nYour role: Answer training compliance questions with data-backed insights.\n"
                     . "Active rules: {$rules}\nOverdue assignments:\n{$overdue}\n"
                     . ($empAsgn !== '[]' ? "Employee assignments:\n{$empAsgn}\n" : '');

            case 'skills_gap':
                $gaps     = json_encode($context['skills_gaps']      ?? [], JSON_PRETTY_PRINT);
                $deptSkls = json_encode($context['department_skills'] ?? [], JSON_PRETTY_PRINT);

                return "{$base}\nYour role: Analyse skills gaps and recommend training interventions.\n"
                     . "Be specific about which courses or development actions address each gap.\n\n"
                     . "INDIVIDUAL GAPS:\n{$gaps}\nDEPARTMENT SKILLS:\n{$deptSkls}";

            case 'summary':
                $title   = $context['course_title']    ?? $context['lesson_title']    ?? '';
                $content = $context['lesson_content']  ?? $context['course_description'] ?? '';
                $lessons = isset($context['lessons'])
                    ? implode(', ', array_column($context['lessons'], 'title'))
                    : '';

                return "{$base}\nYour role: Produce clear, structured learning summaries.\n"
                     . "TITLE: {$title}\n"
                     . ($lessons ? "LESSONS: {$lessons}\n" : '')
                     . ($content ? "CONTENT:\n{$content}\n" : '');

            case 'study_guide':
                $title   = $context['course_title']       ?? '';
                $desc    = $context['course_description'] ?? '';
                $lessons = isset($context['lessons'])
                    ? implode(', ', array_column($context['lessons'], 'title'))
                    : '';

                return "{$base}\nYour role: Create comprehensive study guides for effective learning.\n"
                     . "COURSE: {$title}\nDESCRIPTION: {$desc}\nLESSONS: {$lessons}";

            case 'practice_quiz':
                $title   = $context['course_title']       ?? '';
                $desc    = $context['course_description'] ?? '';
                $lessons = isset($context['lessons'])
                    ? implode(', ', array_column($context['lessons'], 'title'))
                    : '';

                return "{$base}\nYour role: Generate high-quality practice questions.\n"
                     . "Respond with valid JSON ONLY — no prose, no markdown fences.\n"
                     . "COURSE: {$title}\nDESCRIPTION: {$desc}\nLESSONS: {$lessons}";

            default:
                return $base;
        }
    }

    // ── AI calls via multi-provider abstraction ───────────────────────────────

    private function call(string $system, array $history, string $userMessage, int $maxTokens = 0): array
    {
        $max = $maxTokens ?: $this->maxTokens;

        $request = new AgentRequest(
            prompt       : $userMessage,
            module       : $this->module,
            systemPrompt : $system,
            maxTokens    : $max,
            history      : $history,
        );

        $response = $this->agent->ask($request);

        if ($response->failed()) {
            Log::error('[LmsAiChatService] AI call failed', ['error' => $response->errorMessage ?? '']);
            return [
                'text'   => 'I encountered an error processing your request. Please try again.',
                'tokens' => 0,
                'error'  => $response->errorMessage,
            ];
        }

        return ['text' => $response->content, 'tokens' => $response->totalTokens, 'error' => null];
    }

    private function callJson(string $system, array $history, string $userMessage, int $maxTokens = 0): array
    {
        $max = $maxTokens ?: $this->maxTokens;

        $request = (new AgentRequest(
            prompt       : $userMessage,
            module       : $this->module,
            systemPrompt : $system,
            maxTokens    : $max,
            history      : $history,
        ))->expectJson();

        $response = $this->agent->ask($request);

        if ($response->failed()) {
            Log::error('[LmsAiChatService] AI call failed', ['error' => $response->errorMessage ?? '']);
            return ['text' => '', 'tokens' => 0, 'data' => [], 'error' => $response->errorMessage];
        }

        $text = $response->content;
        $text = preg_replace('/^```[a-z]*\n?/m', '', $text);
        $text = preg_replace('/^```$/m', '', $text);
        $data = json_decode(trim($text), true) ?? [];

        return ['text' => $text, 'tokens' => $response->totalTokens, 'data' => $data, 'error' => null];
    }

    // ── History management ────────────────────────────────────────────────────

    private function loadHistory(string $sessionId): array
    {
        return AiChatMessage::where('session_id', $sessionId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->limit($this->historyLimit)
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();
    }

    private function persistTurn(int $userId, string $sessionId, string $question, string $answer, int $tokens): void
    {
        AiChatSession::firstOrCreate(
            ['session_id' => $sessionId],
            ['user_id' => $userId, 'title' => Str::limit($question, 60), 'status' => 'active']
        );

        AiChatMessage::create([
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'role'       => 'user',
            'content'    => $question,
            'metadata'   => ['module' => 'lms'],
        ]);

        AiChatMessage::create([
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'role'       => 'assistant',
            'content'    => $answer,
            'metadata'   => ['module' => 'lms'],
            'tokens_used'=> $tokens,
        ]);
    }

    private function ensureLmsSession(
        int     $userId,
        string  $sessionId,
        string  $contextType,
        ?int    $contextId,
        string  $persona,
        string  $question
    ): void {
        LmsAiSession::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'user_id'      => $userId,
                'context_type' => $contextType,
                'context_id'   => $contextId,
                'persona'      => $persona,
                'title'        => Str::limit($question, 80),
                'status'       => 'active',
            ]
        );

        LmsAiSession::where('session_id', $sessionId)->touch();
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    private function rateCheck(int $userId): void
    {
        $key = "lms-ai-{$userId}";
        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages([
                'question' => 'Too many AI requests. Please wait a moment before asking again.',
            ]);
        }
        RateLimiter::hit($key, 60);
    }
}
