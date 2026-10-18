<?php

namespace App\Modules\HR\LMS\Jobs;

use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsQuizQuestion;
use App\Modules\HR\LMS\Notifications\LmsAiCourseFailedNotification;
use App\Modules\HR\LMS\Notifications\LmsAiCourseReadyNotification;
use App\Core\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateAiCourseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $tries   = 1;   // no automatic retries — notify user on failure instead

    public function __construct(
        private readonly int   $courseId,
        private readonly int   $userId,
        private readonly array $params,
    ) {}

    public function handle(): void
    {
        $course = LmsCourse::find($this->courseId);
        $user   = User::find($this->userId);

        if (!$course || !$user) {
            return;
        }

        try {
            $plan = $this->callOpenAi($course);
            $this->applyPlan($course, $plan);

            $moduleCount  = count($plan['modules'] ?? []);
            $lessonCount  = collect($plan['modules'] ?? [])->sum(fn ($m) => count($m['lessons'] ?? []));
            $quizCount    = count($plan['quiz_questions'] ?? []);

            $user->notify(new LmsAiCourseReadyNotification($course, $moduleCount, $lessonCount, $quizCount));
        } catch (\Throwable $e) {
            Log::error('[GenerateAiCourseJob] Failed', [
                'course_id' => $this->courseId,
                'error'     => $e->getMessage(),
            ]);

            try {
                $user->notify(new LmsAiCourseFailedNotification($course));
            } catch (\Throwable) {
                // Swallow notification errors — the job failure is already logged
            }
        }
    }

    // ── OpenAI call ────────────────────────────────────────────────────────────

    private function callOpenAi(LmsCourse $course): array
    {
        $p = $this->params;

        $levelDescriptions = [
            'intern'       => 'interns with little to no prior experience',
            'junior'       => 'junior professionals with 0-2 years experience',
            'intermediate' => 'mid-level professionals with 2-5 years experience',
            'senior'       => 'senior professionals with 5+ years experience',
            'executive'    => 'executives and senior leaders',
        ];
        $toneDescriptions = [
            'professional'   => 'formal, professional corporate tone',
            'conversational' => 'friendly, engaging, approachable tone',
            'technical'      => 'precise, detailed, technical/academic tone',
        ];

        $levelLabel   = $levelDescriptions[$p['level']] ?? $p['level'];
        $toneLabel    = $toneDescriptions[$p['tone']]   ?? 'professional corporate tone';
        $context      = $p['industry_context'] ?: 'general corporate';
        $numModules   = (int) $p['num_modules'];
        $lessonsPerMod = (int) $p['lessons_per_module'];
        $quizCount    = (int) ($p['quiz_count'] ?? 10);
        $includeQuiz  = (bool) ($p['include_quiz'] ?? true);
        $caseStudies  = (bool) ($p['case_studies'] ?? false);
        $language     = $p['language'] ?? 'English';
        $extraObj     = $p['extra_objectives'] ?? '';

        $caseStudyNote = $caseStudies
            ? 'Include one practical case study lesson per module as the last lesson of that module.'
            : 'Do not include separate case study lessons.';
        $quizNote = $includeQuiz
            ? "Generate exactly {$quizCount} multiple-choice quiz questions."
            : 'Set quiz_questions to an empty array [].';
        $objectivesNote = $extraObj
            ? "Additional learning objectives: {$extraObj}"
            : '';

        $prompt = <<<PROMPT
Course Title: "{$course->title}"
Industry/Context: {$context}
Target Audience: {$levelLabel}
Number of Modules: {$numModules}
Lessons per Module (excluding case studies if requested): {$lessonsPerMod}
Tone: {$toneLabel}
Language: {$language}
{$caseStudyNote}
{$quizNote}
{$objectivesNote}

Generate a complete, high-quality course in valid JSON only. No markdown, no commentary outside the JSON.

Return this exact structure:
{
  "description": "Compelling 2-3 sentence course description.",
  "duration_mins": <total estimated duration as integer>,
  "modules": [
    {
      "title": "Module title",
      "description": "Module description (1-2 sentences)",
      "lessons": [
        {
          "title": "Lesson title",
          "type": "text",
          "content_body": "<h2>Lesson Title</h2><p>Detailed content at least 250 words. Use h2, h3, p, ul/li, strong tags.</p>",
          "duration_mins": 15,
          "is_preview": false
        }
      ]
    }
  ],
  "quiz_questions": [
    {
      "question": "Question text?",
      "options": ["Option A", "Option B", "Option C", "Option D"],
      "correct_index": 0,
      "explanation": "Why this answer is correct."
    }
  ]
}

Rules:
- First lesson of first module must have "is_preview": true.
- Every lesson content_body must be rich HTML (at least 250 words).
- All text must be in {$language}.
- Output ONLY the JSON object. Nothing else.
PROMPT;

        $system = 'You are an expert instructional designer. Generate precise, structured JSON course content with no extra text. Output valid JSON only.';
        $config = config('ai.providers.openai');

        $client = \OpenAI::factory()
            ->withApiKey($config['api_key'])
            ->make();

        $result = $client->chat()->create([
            'model'           => $config['model'] ?? 'gpt-4o',
            'max_tokens'      => 8000,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $prompt],
            ],
        ]);

        $raw  = $result->choices[0]->message->content ?? '';
        $plan = json_decode(trim($raw), true);

        if (!$plan || !isset($plan['modules'])) {
            throw new \RuntimeException('AI returned an invalid response. Raw: ' . substr($raw, 0, 200));
        }

        return $plan;
    }

    // ── Write to DB ────────────────────────────────────────────────────────────

    private function applyPlan(LmsCourse $course, array $plan): void
    {
        DB::transaction(function () use ($course, $plan) {
            $moduleOffset = ($course->modules()->max('sort_order') ?? -1) + 1;

            foreach ($plan['modules'] as $mIdx => $modData) {
                $module = $course->modules()->create([
                    'title'       => $modData['title'],
                    'description' => $modData['description'] ?? null,
                    'sort_order'  => $moduleOffset + $mIdx,
                ]);

                foreach ($modData['lessons'] ?? [] as $lIdx => $lesData) {
                    $module->lessons()->create([
                        'course_id'    => $course->id,
                        'title'        => $lesData['title'],
                        'type'         => in_array($lesData['type'] ?? '', ['video', 'pdf', 'document', 'presentation', 'text', 'link'])
                            ? $lesData['type']
                            : 'text',
                        'content_body' => $lesData['content_body'] ?? null,
                        'external_url' => $lesData['external_url'] ?? null,
                        'duration_mins'=> (int) ($lesData['duration_mins'] ?? 10),
                        'sort_order'   => $lIdx,
                        'is_preview'   => (bool) ($lesData['is_preview'] ?? false),
                    ]);
                }
            }

            $quizOffset = ($course->quizQuestions()->max('sort_order') ?? -1) + 1;

            foreach ($plan['quiz_questions'] ?? [] as $qIdx => $qData) {
                $options = $qData['options'] ?? [];
                if (empty($options)) continue;

                /** @var LmsQuizQuestion $question */
                $question = $course->quizQuestions()->create([
                    'question'   => $qData['question'],
                    'type'       => 'mcq',
                    'sort_order' => $quizOffset + $qIdx,
                ]);

                $correctIdx = (int) ($qData['correct_index'] ?? 0);
                foreach ($options as $oIdx => $optText) {
                    $question->options()->create([
                        'option_text' => $optText,
                        'is_correct'  => $oIdx === $correctIdx,
                        'sort_order'  => $oIdx,
                    ]);
                }
            }

            if (!empty($plan['description'])) {
                $updates = ['description' => $plan['description']];
                if (!empty($plan['duration_mins'])) {
                    $updates['duration_mins'] = (int) $plan['duration_mins'];
                }
                $course->update($updates);
            }
        });
    }
}
