<?php

namespace App\Modules\HR\LMS\Services;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\LmsAiRecommendation;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsLearningPlan;
use App\Modules\HR\LMS\Models\LmsLearningPlanItem;
use App\Modules\HR\LMS\Models\LmsRevisionPlan;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Models\LmsWeakLearningArea;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LmsAiRecommendationService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        // Groq-first (cheap tier); fall back to OpenAI's key/endpoint if the
        // local tier isn't configured. Previously used Claude, which needs an
        // ANTHROPIC_API_KEY that isn't set — so this also fixes the feature.
        $local = (array) config('ai.providers.local', []);
        $oai   = (array) config('ai.providers.openai', []);
        $useLocal = ! empty($local['api_key']);

        $this->apiKey  = (string) ($useLocal ? $local['api_key'] : ($oai['api_key'] ?? ''));
        $this->baseUrl = rtrim((string) ($useLocal ? ($local['base_url'] ?? 'https://api.groq.com/openai/v1') : ($oai['base_url'] ?? 'https://api.openai.com/v1')), '/');
        /*
         * The configured model, with no private fallback.
         *
         * This used to fall back to `llama-3.3-70b-versatile` when the config had no model.
         * Groq decommissioned that name, so the fallback stopped being a safety net and became
         * a guaranteed 404 — and one that reads as "the AI is down" rather than "this service
         * is asking for a model that no longer exists". `config/ai.php` already carries the
         * default; a second copy here is a second thing to update and the one nobody
         * remembers. `LocalAgent`, which is the driver everything else goes through, has always
         * simply trusted the config.
         */
        $this->model   = (string) ($useLocal ? ($local['model'] ?? '') : ($oai['model'] ?? 'gpt-4o'));
    }

    // ── Public API ─────────────────────────────────────────────────────────

    public function recommendCoursesForEmployee(User $user, int $generatedBy): Collection
    {
        $context = $this->buildEmployeeContext($user);
        $courses  = LmsCourse::where('is_published', true)->get(['id', 'title', 'description', 'category_id']);

        $prompt = $this->buildRecommendationPrompt($context, $courses, 'comprehensive');
        $aiResp = $this->callClaude($prompt);

        return $this->persistRecommendations($user->id, $aiResp, $context, $generatedBy);
    }

    public function recommendCoursesByJobTitle(User $user, int $generatedBy): Collection
    {
        $context = $this->buildEmployeeContext($user, ['job_title']);
        $courses  = LmsCourse::where('is_published', true)->get(['id', 'title', 'description']);

        $prompt = $this->buildRecommendationPrompt($context, $courses, 'job_title');
        $aiResp = $this->callClaude($prompt);

        return $this->persistRecommendations($user->id, $aiResp, $context, $generatedBy, 'job_title');
    }

    public function recommendCoursesByPerformanceGap(User $user, int $generatedBy): Collection
    {
        $scores = LmsUnderstandingScore::where('user_id', $user->id)
            ->with('course')
            ->get();

        if ($scores->isEmpty()) {
            return collect();
        }

        $context = $this->buildEmployeeContext($user, ['performance']);
        $context['understanding_scores'] = $scores->map(fn ($s) => [
            'course'      => $s->course?->title,
            'grade'       => $s->grade,
            'total_score' => $s->total_score,
            'flags'       => array_filter([
                $s->guessing_detected       ? 'guessing_detected'       : null,
                $s->rushed_detected         ? 'rushed_detected'         : null,
                $s->low_engagement_detected ? 'low_engagement_detected' : null,
            ]),
        ])->toArray();

        $courses = LmsCourse::where('is_published', true)->get(['id', 'title', 'description']);
        $prompt  = $this->buildRecommendationPrompt($context, $courses, 'performance_gap');
        $aiResp  = $this->callClaude($prompt);

        return $this->persistRecommendations($user->id, $aiResp, $context, $generatedBy, 'performance_gap');
    }

    public function recommendCoursesByFailedTopics(User $user, int $generatedBy): Collection
    {
        $weakAreas = LmsWeakLearningArea::where('user_id', $user->id)
            ->where('is_resolved', false)
            ->with('course')
            ->get();

        if ($weakAreas->isEmpty()) {
            return collect();
        }

        $context = $this->buildEmployeeContext($user, ['failed_topics']);
        $context['weak_learning_areas'] = $weakAreas->map(fn ($w) => [
            'topic'         => $w->topic,
            'course'        => $w->course?->title,
            'failure_count' => $w->failure_count,
        ])->toArray();

        $courses = LmsCourse::where('is_published', true)->get(['id', 'title', 'description']);
        $prompt  = $this->buildRecommendationPrompt($context, $courses, 'failed_topics');
        $aiResp  = $this->callClaude($prompt);

        return $this->persistRecommendations($user->id, $aiResp, $context, $generatedBy, 'failed_topics');
    }

    public function generateLearningPlan(User $user, int $generatedBy): LmsLearningPlan
    {
        $context = $this->buildEmployeeContext($user);
        $context['enrollments'] = $this->getEnrollmentSummary($user->id);
        $context['understanding_scores'] = $this->getScoreSummary($user->id);
        $context['weak_areas'] = $this->getWeakAreaSummary($user->id);

        $courses = LmsCourse::where('is_published', true)->get(['id', 'title', 'description']);
        $prompt  = $this->buildLearningPlanPrompt($context, $courses);
        $aiResp  = $this->callClaude($prompt);

        return $this->persistLearningPlan($user->id, $aiResp, $context, $generatedBy, 'learning_plan');
    }

    public function generatePersonalizedRevisionPlan(User $user, int $enrollmentId, int $generatedBy): LmsRevisionPlan
    {
        $enrollment = LmsEnrollment::with(['course', 'understandingScore'])->findOrFail($enrollmentId);

        $weakAreas = LmsWeakLearningArea::where('user_id', $user->id)
            ->where('course_id', $enrollment->course_id)
            ->where('is_resolved', false)
            ->get();

        $context = $this->buildEmployeeContext($user);
        $context['course']       = ['title' => $enrollment->course?->title, 'description' => $enrollment->course?->description];
        $context['weak_areas']   = $weakAreas->map(fn ($w) => ['topic' => $w->topic, 'failures' => $w->failure_count])->toArray();
        $context['current_score']= $enrollment->understandingScore?->total_score;
        $context['grade']        = $enrollment->understandingScore?->grade;

        $prompt = $this->buildRevisionPlanPrompt($context);
        $aiResp = $this->callClaude($prompt);

        return $this->persistRevisionPlan($user->id, $enrollment, $aiResp, $context, $generatedBy);
    }

    // ── AI prompts ─────────────────────────────────────────────────────────

    private function buildRecommendationPrompt(array $context, $courses, string $focus): string
    {
        $courseList = $courses->map(fn ($c) => "- ID {$c->id}: {$c->title} — {$c->description}")->join("\n");
        $ctx        = json_encode($context, JSON_PRETTY_PRINT);

        $focusInstruction = match ($focus) {
            'job_title'      => 'Focus ONLY on courses that are relevant to the employee\'s current job title and role.',
            'performance_gap'=> 'Focus ONLY on courses that address the performance gaps and low scores shown in the data.',
            'failed_topics'  => 'Focus ONLY on courses that cover the specific topics where the employee has failed repeatedly.',
            default          => 'Consider job role, performance gaps, failed topics, policy requirements, and career growth holistically.',
        };

        return <<<PROMPT
You are an L&D specialist AI. Analyze the employee profile and recommend courses from the catalog.

{$focusInstruction}

## Employee Context
{$ctx}

## Available Courses
{$courseList}

Respond with a JSON array of recommendation objects. Each object must have:
- course_id (integer, from the catalog above)
- recommendation_type (one of: job_title, performance_gap, failed_topics, career_path, policy_requirement, refresher)
- reason (string, 1-2 sentences explaining why this course is recommended for THIS specific employee)
- priority (integer 1-5, where 1 is most urgent)

Return between 3 and 8 recommendations. Return ONLY the JSON array, no prose.
PROMPT;
    }

    private function buildLearningPlanPrompt(array $context, $courses): string
    {
        $courseList = $courses->map(fn ($c) => "- ID {$c->id}: {$c->title} — {$c->description}")->join("\n");
        $ctx        = json_encode($context, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are an L&D specialist AI. Create a structured 90-day learning plan for this employee.

## Employee Context
{$ctx}

## Available Courses
{$courseList}

Respond with a JSON object with these fields:
- title (string, e.g. "Q3 Learning Plan for Jane Smith")
- description (string, 2-3 sentences summarising the plan's objectives)
- target_completion_date (string, ISO date ~90 days from today)
- items (array of plan items)

Each item must have:
- course_id (integer or null if not a course)
- item_type (one of: course, assessment, revision, reading, practice)
- title (string)
- description (string)
- reason (string, why this is in the plan)
- priority (integer 1-5)
- sort_order (integer, 1-based)
- due_date (string, ISO date, spread across the 90 days)

Return ONLY the JSON object, no prose.
PROMPT;
    }

    private function buildRevisionPlanPrompt(array $context): string
    {
        $ctx = json_encode($context, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are an L&D specialist AI. Create a personalised revision plan to help this employee improve their understanding of the course.

## Employee & Course Context
{$ctx}

Respond with a JSON object with these fields:
- topic_areas (array of strings, the main topic areas to cover)
- estimated_hours (number, realistic hours needed for revision)
- ai_suggestions (array of suggestion objects)

Each suggestion object must have:
- topic (string)
- activity (string, specific revision activity, e.g. "Re-read module 3 on X", "Practice quiz on Y")
- estimated_minutes (integer)
- priority (integer 1-5)

Return ONLY the JSON object, no prose.
PROMPT;
    }

    // ── AI call (Groq / OpenAI-compatible) ───────────────────────────────────

    private function callClaude(string $prompt): array
    {
        try {
            // Route through the free-first provider chain instead of a single endpoint.
            $text = app(\App\Core\AI\Services\AIChatProviderChain::class)->text(
                'You are an expert L&D AI. You must respond with valid JSON only. No prose, no markdown fences.',
                $prompt,
                ['max_tokens' => 2048, 'module' => 'lms']
            );

            if ($text === null) {
                return [];
            }

            $text = preg_replace('/^```[a-z]*\n?/m', '', $text);
            $text = preg_replace('/^```$/m', '', $text);

            return json_decode(trim($text), true) ?? [];
        } catch (\Throwable $e) {
            Log::error('[LmsAiRecommendationService] AI call failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ── Persistence ────────────────────────────────────────────────────────

    private function persistRecommendations(
        int $userId,
        array $aiResponse,
        array $context,
        int $generatedBy,
        string $defaultType = 'comprehensive'
    ): Collection {
        $items = [];

        // Handle both root array and nested "recommendations" key
        $recs = isset($aiResponse[0]) ? $aiResponse : ($aiResponse['recommendations'] ?? []);

        foreach ($recs as $rec) {
            $courseId = $rec['course_id'] ?? null;
            // Validate course exists
            if ($courseId && !LmsCourse::where('id', $courseId)->exists()) {
                continue;
            }

            $items[] = LmsAiRecommendation::create([
                'user_id'             => $userId,
                'course_id'           => $courseId,
                'recommendation_type' => $rec['recommendation_type'] ?? $defaultType,
                'reason'              => $rec['reason'] ?? '',
                'priority'            => max(1, min(5, (int) ($rec['priority'] ?? 3))),
                'ai_context'          => $context,
                'ai_response'         => $rec,
                'status'              => 'pending',
                'generated_at'        => now(),
                'expires_at'          => now()->addDays(90),
                'created_by'          => $generatedBy,
            ]);
        }

        return collect($items);
    }

    private function persistLearningPlan(
        int $userId,
        array $aiResponse,
        array $context,
        int $generatedBy,
        string $planType
    ): LmsLearningPlan {
        $plan = LmsLearningPlan::create([
            'user_id'                => $userId,
            'title'                  => $aiResponse['title'] ?? 'AI Learning Plan',
            'description'            => $aiResponse['description'] ?? null,
            'plan_type'              => $planType,
            'status'                 => 'active',
            'ai_generated'           => true,
            'ai_context'             => $context,
            'target_completion_date' => $aiResponse['target_completion_date'] ?? now()->addDays(90)->toDateString(),
            'generated_by'           => $generatedBy,
        ]);

        foreach ($aiResponse['items'] ?? [] as $idx => $item) {
            $courseId = $item['course_id'] ?? null;
            if ($courseId && !LmsCourse::where('id', $courseId)->exists()) {
                $courseId = null;
            }

            LmsLearningPlanItem::create([
                'learning_plan_id' => $plan->id,
                'course_id'        => $courseId,
                'item_type'        => $item['item_type'] ?? 'course',
                'title'            => $item['title'] ?? "Step " . ($idx + 1),
                'description'      => $item['description'] ?? null,
                'reason'           => $item['reason'] ?? null,
                'priority'         => max(1, min(5, (int) ($item['priority'] ?? 3))),
                'sort_order'       => (int) ($item['sort_order'] ?? $idx + 1),
                'due_date'         => $item['due_date'] ?? null,
                'status'           => 'pending',
            ]);
        }

        return $plan->load('items');
    }

    private function persistRevisionPlan(
        int $userId,
        LmsEnrollment $enrollment,
        array $aiResponse,
        array $context,
        int $generatedBy
    ): LmsRevisionPlan {
        return LmsRevisionPlan::create([
            'user_id'        => $userId,
            'enrollment_id'  => $enrollment->id,
            'course_id'      => $enrollment->course_id,
            'topic_areas'    => $aiResponse['topic_areas'] ?? [],
            'weak_areas'     => $context['weak_areas'] ?? [],
            'ai_suggestions' => $aiResponse['ai_suggestions'] ?? [],
            'estimated_hours'=> $aiResponse['estimated_hours'] ?? null,
            'status'         => 'active',
            'generated_at'   => now(),
            'generated_by'   => $generatedBy,
        ]);
    }

    // ── Context builders ───────────────────────────────────────────────────

    private function buildEmployeeContext(User $user, array $include = []): array
    {
        $person = $user->person;

        $ctx = [
            'employee_name' => $person ? trim("{$person->first_name} {$person->surname}") : $user->email,
        ];

        if (empty($include) || in_array('job_title', $include)) {
            $ctx['job_title']  = $person?->hrPosition?->name ?? 'Unknown';
            $ctx['department'] = $person?->department ?? $person?->divisionLevel1?->name ?? 'Unknown';
        }

        return $ctx;
    }

    private function getEnrollmentSummary(int $userId): array
    {
        return LmsEnrollment::where('user_id', $userId)
            ->with('course:id,title')
            ->get()
            ->map(fn ($e) => [
                'course'      => $e->course?->title,
                'status'      => $e->status,
                'progress'    => $e->progress_pct,
                'score'       => $e->score,
            ])
            ->toArray();
    }

    private function getScoreSummary(int $userId): array
    {
        return LmsUnderstandingScore::where('user_id', $userId)
            ->with('course:id,title')
            ->get()
            ->map(fn ($s) => [
                'course' => $s->course?->title,
                'grade'  => $s->grade,
                'score'  => $s->total_score,
            ])
            ->toArray();
    }

    private function getWeakAreaSummary(int $userId): array
    {
        return LmsWeakLearningArea::where('user_id', $userId)
            ->where('is_resolved', false)
            ->with('course:id,title')
            ->get()
            ->map(fn ($w) => ['topic' => $w->topic, 'failures' => $w->failure_count, 'course' => $w->course?->title])
            ->toArray();
    }
}
