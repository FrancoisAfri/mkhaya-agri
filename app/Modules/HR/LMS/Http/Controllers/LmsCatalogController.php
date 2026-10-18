<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsCategory;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsLearningPath;
use App\Modules\HR\LMS\Models\LmsLesson;
use App\Modules\HR\LMS\Models\LmsLessonCompletion;
use App\Modules\HR\LMS\Models\LmsQuizAttempt;
use App\Modules\HR\LMS\Notifications\LmsCertificateIssuedNotification;
use App\Modules\HR\LMS\Notifications\LmsCourseCompletedManagerNotification;
use App\Modules\HR\LMS\Notifications\LmsCourseCompletedNotification;
use App\Modules\HR\LMS\Notifications\LmsCourseEnrolledNotification;
use App\Modules\HR\LMS\Services\LmsPolicyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsCatalogController extends Controller
{
    public function __construct(private readonly LmsPolicyGuard $policyGuard) {}
    public function index(Request $request): Response
    {
        $userId = Auth::id();

        $categories = LmsCategory::orderBy('sort_order')->orderBy('name')->get();

        $coursesQuery = LmsCourse::with(['category'])
            ->where('is_published', true)
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'ilike', "%{$request->search}%"))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->category))
            ->orderBy('is_mandatory', 'desc')
            ->orderBy('title');

        $courses = $coursesQuery->get()->map(function (LmsCourse $course) use ($userId) {
            $enrollment = LmsEnrollment::where('user_id', $userId)
                ->where('course_id', $course->id)
                ->first();

            return [
                'id'           => $course->id,
                'uuid'         => $course->uuid,
                'title'        => $course->title,
                'description'  => $course->description,
                'cover_image'  => $course->cover_image,
                'type'         => $course->type,
                'duration_mins'=> $course->duration_mins,
                'pass_score'   => $course->pass_score,
                'is_mandatory' => $course->is_mandatory,
                'category'     => $course->category ? [
                    'id'    => $course->category->id,
                    'name'  => $course->category->name,
                    'color' => $course->category->color,
                    'icon'  => $course->category->icon,
                ] : null,
                'enrollment'   => $enrollment ? [
                    'id'           => $enrollment->id,
                    'status'       => $enrollment->status,
                    'progress_pct' => $enrollment->progress_pct,
                    'score'        => $enrollment->score,
                    'completed_at' => $enrollment->completed_at?->toDateTimeString(),
                ] : null,
            ];
        });

        $featuredPaths = LmsLearningPath::where('is_published', true)
            ->withCount('pathCourses')
            ->orderBy('is_mandatory', 'desc')
            ->limit(3)
            ->get()
            ->map(fn ($p) => [
                'id'              => $p->id,
                'title'           => $p->title,
                'description'     => $p->description,
                'is_mandatory'    => $p->is_mandatory,
                'path_courses_count' => $p->path_courses_count,
            ]);

        return Inertia::render('Modules/LMS/Catalog', [
            'categories'    => $categories,
            'courses'       => $courses,
            'featuredPaths' => $featuredPaths,
            'filters'       => $request->only('search', 'category'),
        ]);
    }

    public function show(LmsCourse $course): Response
    {
        /** @var \App\Core\User\Models\User $authUser */
        $authUser = Auth::user();
        $isAdmin  = $authUser->hasAnyRole(['administrator', 'super admin', 'HR', 'executive']);

        // Unpublished courses are only visible to admins (e.g. preview from the builder)
        abort_unless($course->is_published || $isAdmin, 404);

        $userId = Auth::id();

        $course->load(['category', 'contents', 'quizQuestions.options', 'modules.lessons.materials']);

        $enrollment = LmsEnrollment::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->with('certificate')
            ->first();

        $certificate = $enrollment?->certificate;

        // Completed lesson IDs for this enrollment
        $completedLessonIds = $enrollment
            ? LmsLessonCompletion::where('enrollment_id', $enrollment->id)->pluck('lesson_id')->toArray()
            : [];

        return Inertia::render('Modules/LMS/Course', [
            'course' => [
                'id'                 => $course->id,
                'uuid'               => $course->uuid,
                'title'              => $course->title,
                'description'        => $course->description,
                'cover_image'        => $course->cover_image,
                'type'               => $course->type,
                'duration_mins'      => $course->duration_mins,
                'pass_score'         => $course->pass_score,
                'expires_after_days' => $course->expires_after_days,
                'is_mandatory'       => $course->is_mandatory,
                'category'           => $course->category ? [
                    'id'    => $course->category->id,
                    'name'  => $course->category->name,
                    'color' => $course->category->color,
                ] : null,
                // Legacy flat contents kept for backward compat
                'contents' => $course->contents->map(fn ($c) => [
                    'id'           => $c->id,
                    'type'         => $c->type,
                    'title'        => $c->title,
                    'body'         => $c->body,
                    'url'          => $c->url,
                    'file_path'    => $c->file_path,
                    'sort_order'   => $c->sort_order,
                    'duration_mins'=> $c->duration_mins,
                ]),
                // Phase-2 structured modules
                'modules' => $course->modules->map(fn ($m) => [
                    'id'          => $m->id,
                    'title'       => $m->title,
                    'description' => $m->description,
                    'sort_order'  => $m->sort_order,
                    'lessons'     => $m->lessons->map(fn ($l) => [
                        'id'           => $l->id,
                        'title'        => $l->title,
                        'type'         => $l->type,
                        'content_body' => ($enrollment || $l->is_preview) ? $l->content_body : null,
                        'external_url' => ($enrollment || $l->is_preview) ? $l->external_url : null,
                        'duration_mins'=> $l->duration_mins,
                        'is_preview'   => $l->is_preview,
                        'is_completed' => in_array($l->id, $completedLessonIds),
                        'materials'    => ($enrollment || $l->is_preview) ? $l->materials->map(fn ($mat) => [
                            'id'        => $mat->id,
                            'title'     => $mat->title,
                            'type'      => $mat->type,
                            'file_name' => $mat->file_name,
                            'size_mb'   => $mat->size_mb,
                            'url'       => $mat->url,
                        ]) : [],
                    ]),
                ]),
                'has_quiz' => $course->quizQuestions->count() > 0,
                'quiz_questions' => $enrollment ? $course->quizQuestions->map(fn ($q) => [
                    'id'       => $q->id,
                    'question' => $q->question,
                    'type'     => $q->type,
                    'options'  => $q->options->map(fn ($o) => [
                        'id'          => $o->id,
                        'option_text' => $o->option_text,
                        'sort_order'  => $o->sort_order,
                    ]),
                ]) : [],
            ],
            'enrollment' => $enrollment ? [
                'id'           => $enrollment->id,
                'uuid'         => $enrollment->uuid,
                'status'       => $enrollment->status,
                'progress_pct' => $enrollment->progress_pct,
                'score'        => $enrollment->score,
                'enrolled_at'  => $enrollment->enrolled_at?->toDateTimeString(),
                'completed_at' => $enrollment->completed_at?->toDateTimeString(),
            ] : null,
            'certificate' => $certificate ? [
                'id'                 => $certificate->id,
                'certificate_number' => $certificate->certificate_number,
                'issued_at'          => $certificate->issued_at?->toDateTimeString(),
                'expires_at'         => $certificate->expires_at?->toDateTimeString(),
            ] : null,
        ]);
    }

    public function enroll(LmsCourse $course): JsonResponse
    {
        /** @var \App\Core\User\Models\User $authUser */
        $authUser = Auth::user();
        $isAdmin  = $authUser->hasAnyRole(['administrator', 'super admin', 'HR', 'executive']);
        abort_unless($course->is_published || $isAdmin, 404);

        $userId = Auth::id();

        // Check prerequisites: all must be completed by this user
        $prerequisites = $course->prerequisites()->with('prerequisiteCourse')->get();

        foreach ($prerequisites as $prereq) {
            $done = LmsEnrollment::where('user_id', $userId)
                ->where('course_id', $prereq->prerequisite_course_id)
                ->where('status', 'completed')
                ->exists();

            if (!$done) {
                return response()->json([
                    'error'      => 'prerequisite_not_met',
                    'message'    => "You must complete \"{$prereq->prerequisiteCourse->title}\" before enrolling.",
                    'prerequisite' => [
                        'id'    => $prereq->prerequisiteCourse->id,
                        'title' => $prereq->prerequisiteCourse->title,
                    ],
                ], 422);
            }
        }

        // Policy check: validate enrollment against any active LMS policies
        $policyResult = $this->policyGuard->forCourseEnrollment($course, $userId, $userId);

        $enrollment = LmsEnrollment::firstOrCreate(
            [
                'user_id'   => $userId,
                'course_id' => $course->id,
            ],
            [
                'status'      => 'enrolled',
                'progress_pct'=> 0,
                'enrolled_at' => now(),
            ]
        );

        if ($enrollment->wasRecentlyCreated) {
            $enrollment->load('course');
            $user = $enrollment->user;
            $this->notifySilently($user, new LmsCourseEnrolledNotification($enrollment));
        }

        return response()->json([
            'enrollment' => [
                'id'           => $enrollment->id,
                'uuid'         => $enrollment->uuid,
                'status'       => $enrollment->status,
                'progress_pct' => $enrollment->progress_pct,
            ],
            'created'   => $enrollment->wasRecentlyCreated,
            'warnings'  => $policyResult['warnings'],
        ]);
    }

    public function learn(LmsCourse $course, LmsLesson $lesson): Response
    {
        /** @var \App\Core\User\Models\User $authUser */
        $authUser = Auth::user();
        $isAdmin  = $authUser->hasAnyRole(['administrator', 'super admin', 'HR', 'executive']);

        abort_unless($course->is_published || $isAdmin, 404);
        abort_unless($lesson->course_id === $course->id, 404);

        $userId     = Auth::id();
        $enrollment = LmsEnrollment::where('user_id', $userId)->where('course_id', $course->id)->first();

        abort_unless($enrollment || $lesson->is_preview || $isAdmin, 403);

        $course->load(['modules' => fn ($q) => $q->orderBy('sort_order')->with(['lessons' => fn ($q2) => $q2->orderBy('sort_order')])]);

        $allLessons = $course->modules->flatMap(fn ($m) => $m->lessons)->values();
        $lessonIds  = $allLessons->pluck('id')->all();
        $currentIdx = array_search($lesson->id, $lessonIds);

        abort_unless($currentIdx !== false, 404);

        $completedIds = $enrollment
            ? LmsLessonCompletion::where('enrollment_id', $enrollment->id)->pluck('lesson_id')->all()
            : [];

        $lesson->load('materials');

        return Inertia::render('Modules/LMS/Learn', [
            'course' => [
                'id'       => $course->id,
                'uuid'     => $course->uuid,
                'title'    => $course->title,
                'has_quiz' => $course->quizQuestions()->exists(),
                'modules'  => $course->modules->map(fn ($m) => [
                    'id'      => $m->id,
                    'title'   => $m->title,
                    'lessons' => $m->lessons->map(fn ($l) => [
                        'id'           => $l->id,
                        'title'        => $l->title,
                        'type'         => $l->type,
                        'duration_mins'=> $l->duration_mins,
                        'is_completed' => in_array($l->id, $completedIds),
                    ]),
                ]),
            ],
            'enrollment' => $enrollment ? [
                'uuid'         => $enrollment->uuid,
                'status'       => $enrollment->status,
                'progress_pct' => $enrollment->progress_pct,
            ] : null,
            'lesson' => [
                'id'           => $lesson->id,
                'title'        => $lesson->title,
                'type'         => $lesson->type,
                'content_body' => $lesson->content_body,
                'external_url' => $lesson->external_url,
                'duration_mins'=> $lesson->duration_mins,
                'is_preview'   => $lesson->is_preview,
                'is_completed' => in_array($lesson->id, $completedIds),
                'materials'    => $lesson->materials->map(fn ($mat) => [
                    'id'        => $mat->id,
                    'title'     => $mat->title,
                    'type'      => $mat->type,
                    'file_name' => $mat->file_name,
                    'size_mb'   => $mat->size_mb,
                    'url'       => $mat->url,
                ]),
            ],
            'prevLessonId' => $currentIdx > 0 ? $lessonIds[$currentIdx - 1] : null,
            'nextLessonId' => $currentIdx < count($lessonIds) - 1 ? $lessonIds[$currentIdx + 1] : null,
            'lessonIndex'  => $currentIdx + 1,
            'totalLessons' => count($lessonIds),
        ]);
    }

    public function completeLesson(LmsEnrollment $enrollment, LmsLesson $lesson): JsonResponse
    {
        abort_unless($enrollment->user_id === Auth::id(), 403);
        abort_unless($lesson->course_id === $enrollment->course_id, 422);

        LmsLessonCompletion::firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id],
            ['completed_at'  => now()]
        );

        // Recalculate progress: completed lessons / total lessons in course
        $totalLessons    = LmsLesson::where('course_id', $enrollment->course_id)->count();
        $completedLessons = LmsLessonCompletion::where('enrollment_id', $enrollment->id)->count();

        $progress = $totalLessons > 0
            ? (int) round(($completedLessons / $totalLessons) * 100)
            : 0;

        $data = ['progress_pct' => $progress];

        if (is_null($enrollment->started_at)) {
            $data['started_at'] = now();
            $data['status']     = 'in_progress';
        }

        if ($progress >= 100) {
            $hasQuiz = $enrollment->course()->with('quizQuestions')->first()->quizQuestions()->exists();
            if (!$hasQuiz) {
                $data['status']       = 'completed';
                $data['completed_at'] = now();
            } else {
                $data['status'] = 'in_progress';
            }
        } elseif ($enrollment->status === 'enrolled') {
            $data['status'] = 'in_progress';
        }

        $wasCompleted = isset($data['status']) && $data['status'] === 'completed';

        $enrollment->update($data);
        $enrollment->refresh();

        if ($wasCompleted) {
            $enrollment->load('course');
            $this->dispatchCompletionNotifications($enrollment);
        }

        return response()->json([
            'progress_pct'     => $enrollment->progress_pct,
            'status'           => $enrollment->status,
            'completed_lessons'=> $completedLessons,
            'total_lessons'    => $totalLessons,
        ]);
    }

    public function updateProgress(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->user_id === Auth::id(), 403);

        $request->validate([
            'progress_pct' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $progress = (int) $request->progress_pct;

        $data = [
            'progress_pct' => $progress,
        ];

        if (is_null($enrollment->started_at)) {
            $data['started_at'] = now();
            $data['status']     = 'in_progress';
        }

        if ($progress >= 100) {
            $data['progress_pct'] = 100;

            $hasQuiz = $enrollment->course()->with('quizQuestions')->first()->quizQuestions()->exists();

            if (!$hasQuiz) {
                $data['status']       = 'completed';
                $data['completed_at'] = now();
            }
            // If quiz exists, keep in_progress until quiz is passed
        } elseif ($enrollment->status === 'enrolled' || is_null($enrollment->started_at)) {
            $data['status'] = 'in_progress';
        }

        $wasCompleted = isset($data['status']) && $data['status'] === 'completed';

        $enrollment->update($data);

        if ($wasCompleted) {
            $enrollment->load('course');
            $this->dispatchCompletionNotifications($enrollment);
        }

        return response()->json([
            'enrollment' => [
                'id'           => $enrollment->id,
                'status'       => $enrollment->status,
                'progress_pct' => $enrollment->progress_pct,
            ],
        ]);
    }

    public function submitQuiz(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->user_id === Auth::id(), 403);

        $request->validate([
            'answers'               => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'exists:lms_quiz_questions,id'],
            'answers.*.option_ids'  => ['required', 'array'],
            'answers.*.option_ids.*'=> ['integer', 'exists:lms_quiz_options,id'],
        ]);

        $course     = $enrollment->course()->with('quizQuestions.options')->first();
        $questions  = $course->quizQuestions;
        $totalCount = $questions->count();

        if ($totalCount === 0) {
            return response()->json(['error' => 'No quiz questions for this course.'], 422);
        }

        $correctCount = 0;

        foreach ($request->answers as $answer) {
            $question = $questions->firstWhere('id', $answer['question_id']);
            if (!$question) {
                continue;
            }

            $correctOptionIds = $question->options
                ->where('is_correct', true)
                ->pluck('id')
                ->sort()
                ->values()
                ->toArray();

            $submittedIds = collect($answer['option_ids'])->sort()->values()->toArray();

            if ($correctOptionIds === $submittedIds) {
                $correctCount++;
            }
        }

        $score  = (int) round(($correctCount / $totalCount) * 100);
        $passed = $score >= $course->pass_score;

        LmsQuizAttempt::create([
            'enrollment_id' => $enrollment->id,
            'score'         => $score,
            'passed'        => $passed,
            'answers'       => $request->answers,
            'attempted_at'  => now(),
        ]);

        $updateData = [
            'score'  => $score,
            'status' => $passed ? 'completed' : 'failed',
        ];

        if ($passed) {
            $updateData['completed_at'] = now();
            $updateData['progress_pct'] = 100;
        }

        $enrollment->update($updateData);

        $certificate = null;

        if ($passed) {
            $certificateNumber = 'CERT-' . now()->year . '-' . $enrollment->id;

            $certificate = LmsCertificate::firstOrCreate(
                ['enrollment_id' => $enrollment->id],
                [
                    'certificate_number' => $certificateNumber,
                    'issued_at'          => now(),
                ]
            );

            // Policy check: validate certificate issuance
            $enrollment->refresh();
            $this->policyGuard->forCertificateRequested($enrollment, $enrollment->user_id);

            // Policy check: course completion
            $this->policyGuard->forCourseCompletion($enrollment, $enrollment->user_id);

            // Notifications: completion + certificate
            $enrollment->load('course');
            $this->dispatchCompletionNotifications($enrollment);

            if ($certificate->wasRecentlyCreated) {
                $certificate->load('enrollment.course');
                $user = $enrollment->user;
                $this->notifySilently($user, new LmsCertificateIssuedNotification($certificate));
            }
        }

        return response()->json([
            'score'       => $score,
            'passed'      => $passed,
            'pass_score'  => $course->pass_score,
            'enrollment'  => [
                'id'     => $enrollment->id,
                'status' => $enrollment->status,
                'score'  => $enrollment->score,
            ],
            'certificate' => $certificate ? [
                'id'                 => $certificate->id,
                'certificate_number' => $certificate->certificate_number,
                'issued_at'          => $certificate->issued_at?->toDateTimeString(),
            ] : null,
        ]);
    }

    private function dispatchCompletionNotifications(LmsEnrollment $enrollment): void
    {
        $user = $enrollment->user()->with('person.manager.user')->first();
        if (!$user) {
            return;
        }

        $this->notifySilently($user, new LmsCourseCompletedNotification($enrollment));

        $managerUser = $user->person?->manager?->user;
        if ($managerUser) {
            $employeeName = trim(($user->person->first_name ?? '') . ' ' . ($user->person->surname ?? '')) ?: $user->name;
            $this->notifySilently($managerUser, new LmsCourseCompletedManagerNotification($enrollment, $employeeName));
        }
    }

    private function notifySilently(?\App\Core\User\Models\User $user, \Illuminate\Notifications\Notification $notification): void
    {
        if (!$user) {
            return;
        }
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LMS notification failed', [
                'user_id' => $user->id,
                'class'   => get_class($notification),
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
