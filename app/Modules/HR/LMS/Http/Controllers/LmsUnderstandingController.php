<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use App\Modules\HR\LMS\Models\LmsLearningGapReport;
use App\Modules\HR\LMS\Models\LmsUnderstandingScore;
use App\Modules\HR\LMS\Models\LmsWeakLearningArea;
use App\Modules\HR\LMS\Services\LmsPolicyGuard;
use App\Modules\HR\LMS\Services\LmsUnderstandingScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsUnderstandingController extends Controller
{
    public function __construct(
        private readonly LmsUnderstandingScoreService $service,
        private readonly LmsPolicyGuard               $policyGuard,
    ) {}

    // ── Admin: list all understanding scores ─────────────────────────────────

    public function index(Request $request): Response
    {
        $query = LmsUnderstandingScore::with(['user.person', 'course', 'enrollment'])
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->grade,     fn ($q) => $q->where('grade', $request->grade))
            ->when($request->flag === 'guessing',    fn ($q) => $q->where('guessing_detected', true))
            ->when($request->flag === 'rushed',      fn ($q) => $q->where('rushed_detected', true))
            ->when($request->flag === 'low_engage',  fn ($q) => $q->where('low_engagement_detected', true))
            ->when($request->flag === 'cert_blocked',fn ($q) => $q->where('is_certificate_blocked', true))
            ->when($request->flag === 'refresher',   fn ($q) => $q->where('refresher_recommended', true))
            ->latest('calculated_at');

        $scores = $query->paginate(40)->through(fn ($s) => $this->formatScore($s));

        $courses = LmsCourse::where('is_published', true)
            ->orderBy('title')
            ->get(['id', 'title']);

        return Inertia::render('Modules/LMS/Admin/UnderstandingScores', [
            'scores'       => $scores,
            'courses'      => $courses,
            'filters'      => $request->only('course_id', 'grade', 'flag'),
            'gradeColors'  => LmsUnderstandingScore::GRADE_COLORS,
            'weights'      => LmsUnderstandingScoreService::WEIGHTS,
        ]);
    }

    // ── Admin: gap report for one enrollment ─────────────────────────────────

    public function show(LmsUnderstandingScore $score): Response
    {
        $score->load([
            'user.person',
            'course',
            'enrollment',
            'managerConfirmedBy.person',
        ]);

        $weakAreas = LmsWeakLearningArea::where('user_id', $score->user_id)
            ->where('course_id', $score->course_id)
            ->where('is_resolved', false)
            ->with('question')
            ->orderByDesc('failure_count')
            ->get()
            ->map(fn ($w) => [
                'id'            => $w->id,
                'topic'         => $w->topic ?? '(Unknown topic)',
                'failure_count' => $w->failure_count,
                'last_failed_at'=> $w->last_failed_at?->toDateString(),
            ]);

        $report = LmsLearningGapReport::where('enrollment_id', $score->enrollment_id)
            ->latest()
            ->first();

        $refreshers = $this->service->recommendRefresherTraining($score->user_id);

        return Inertia::render('Modules/LMS/Admin/LearningGapReport', [
            'score'     => array_merge($this->formatScore($score), [
                'lesson_completion_score' => $score->lesson_completion_score,
                'quiz_score'              => $score->quiz_score,
                'practical_score'         => $score->practical_score,
                'time_score'              => $score->time_score,
                'manager_score'           => $score->manager_score,
                'certificate_blocked_reason' => $score->certificate_blocked_reason,
                'manager_confirmed_at'    => $score->manager_confirmed_at?->toDateTimeString(),
                'confirmed_by_name'       => $score->managerConfirmedBy?->person
                    ? $score->managerConfirmedBy->person->first_name . ' ' . $score->managerConfirmedBy->person->surname
                    : null,
            ]),
            'weakAreas' => $weakAreas,
            'report'    => $report ? [
                'id'              => $report->id,
                'recommendations' => $report->recommendations ?? [],
                'generated_at'    => $report->generated_at?->toDateTimeString(),
            ] : null,
            'refreshers'=> $refreshers,
            'weights'   => LmsUnderstandingScoreService::WEIGHTS,
        ]);
    }

    // ── Recalculate for one enrollment ───────────────────────────────────────

    public function calculate(LmsEnrollment $enrollment): RedirectResponse
    {
        $enrollment->loadMissing(['course', 'user']);
        $this->service->calculateUnderstandingScore($enrollment);

        return redirect()->back()->with('success', 'Understanding score recalculated.');
    }

    // ── Manager confirmation ─────────────────────────────────────────────────

    public function confirm(LmsUnderstandingScore $score): RedirectResponse
    {
        $score->loadMissing('enrollment.course');

        // Policy check: validate understanding threshold before confirming
        $this->policyGuard->forManagerConfirmation(
            $score->id,
            $score->user_id,
            (float) ($score->final_score ?? $score->score ?? 0),
            70.0,
            Auth::id(),
        );

        $this->service->confirmManagerScore($score, Auth::id());

        return redirect()->back()->with('success', 'Manager confirmation saved. Score updated.');
    }

    // ── Generate / refresh gap report ────────────────────────────────────────

    public function generateReport(LmsEnrollment $enrollment): RedirectResponse
    {
        $enrollment->loadMissing(['course', 'user']);
        $score      = $this->service->calculateUnderstandingScore($enrollment);
        $refreshers = $this->service->recommendRefresherTraining($enrollment->user_id);

        $weakAreasCount = LmsWeakLearningArea::where('user_id', $enrollment->user_id)
            ->where('course_id', $enrollment->course_id)
            ->where('is_resolved', false)
            ->count();

        $recommendations = collect($refreshers)->flatMap(fn ($r) => $r['reasons'])->unique()->values()->all();

        LmsLearningGapReport::updateOrCreate(
            ['enrollment_id' => $enrollment->id],
            [
                'user_id'                  => $enrollment->user_id,
                'course_id'                => $enrollment->course_id,
                'report_type'              => 'individual',
                'guessing_detected'        => $score->guessing_detected,
                'rushed_detected'          => $score->rushed_detected,
                'low_engagement_detected'  => $score->low_engagement_detected,
                'total_understanding_score'=> $score->total_score,
                'pass_threshold'           => $enrollment->course->pass_score,
                'passed'                   => !$score->is_certificate_blocked,
                'weak_areas_count'         => $weakAreasCount,
                'recommendations'          => $recommendations,
                'generated_at'             => now(),
                'generated_by'             => Auth::id(),
            ]
        );

        return redirect()->back()->with('success', 'Learning gap report generated.');
    }

    // ── Learner: track time spent on enrollment ───────────────────────────────

    public function trackTime(Request $request, LmsEnrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->user_id === Auth::id(), 403);

        $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:480'],
        ]);

        $enrollment->increment('time_spent_mins', $request->minutes);

        return response()->json(['time_spent_mins' => $enrollment->fresh()->time_spent_mins]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function formatScore(LmsUnderstandingScore $s): array
    {
        return [
            'id'                      => $s->id,
            'enrollment_id'           => $s->enrollment_id,
            'learner_name'            => $s->user?->person
                ? $s->user->person->first_name . ' ' . $s->user->person->surname
                : '—',
            'course_id'               => $s->course_id,
            'course_title'            => $s->course->title,
            'total_score'             => $s->total_score,
            'grade'                   => $s->grade,
            'grade_color'             => LmsUnderstandingScore::GRADE_COLORS[$s->grade] ?? 'grey',
            'guessing_detected'       => $s->guessing_detected,
            'rushed_detected'         => $s->rushed_detected,
            'low_engagement_detected' => $s->low_engagement_detected,
            'is_certificate_blocked'  => $s->is_certificate_blocked,
            'refresher_recommended'   => $s->refresher_recommended,
            'manager_confirmed'       => $s->manager_confirmed,
            'calculated_at'           => $s->calculated_at?->toDateString(),
        ];
    }
}
