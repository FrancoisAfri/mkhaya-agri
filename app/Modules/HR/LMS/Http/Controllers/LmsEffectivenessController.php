<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\LmsTrainingCost;
use App\Modules\HR\LMS\Services\LmsEffectivenessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsEffectivenessController extends Controller
{
    public function __construct(private readonly LmsEffectivenessService $service) {}

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard(Request $request): Response
    {
        $filters  = $request->only(['course_id', 'period_start', 'period_end']);
        $data     = $this->service->getDashboard($filters);
        $courses  = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);

        return Inertia::render('Modules/LMS/Admin/EffectivenessDashboard', [
            'kpi'           => $data['kpi'],
            'courseRows'    => $data['courseRows'],
            'recentReports' => $data['recentReports'],
            'courses'       => $courses,
            'filters'       => $filters,
        ]);
    }

    // ── Per-course live analysis ───────────────────────────────────────────────

    public function analyse(Request $request, int $courseId): Response
    {
        $filters = $request->only(['period_start', 'period_end', 'cycle_id']);

        $completion    = $this->service->calculateTrainingCompletionRate($courseId, $filters);
        $passRate      = $this->service->calculateTrainingPassRate($courseId, $filters);
        $understanding = $this->service->calculateUnderstandingImprovement($courseId, $filters);
        $performance   = $this->service->measurePostTrainingImprovement($courseId, $filters);
        $vsAppraisal   = $this->service->compareTrainingToPerformance($courseId, $filters['cycle_id'] ?? null);
        $vsIncidents   = $this->service->compareTrainingToIncidentReduction($courseId, $filters);
        $roi           = $this->service->calculateTrainingROI($courseId, $filters);

        $course  = LmsCourse::findOrFail($courseId, ['id', 'title']);
        $courses = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);

        // Fetch/list training costs for this course
        $costs = LmsTrainingCost::where('course_id', $courseId)
            ->orderByDesc('period_start')
            ->get();

        return Inertia::render('Modules/LMS/Admin/EffectivenessDashboard', [
            'course'       => $course,
            'completion'   => $completion,
            'passRate'     => $passRate,
            'understanding'=> $understanding,
            'performance'  => $performance,
            'vsAppraisal'  => $vsAppraisal,
            'vsIncidents'  => $vsIncidents,
            'roi'          => $roi,
            'costs'        => $costs,
            'courses'      => $courses,
            'filters'      => $filters,
            'activeTab'    => 'analyse',
        ]);
    }

    // ── Generate & save report ─────────────────────────────────────────────────

    public function generateReport(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id'    => 'required|exists:lms_courses,id',
            'period_start' => 'nullable|date',
            'period_end'   => 'nullable|date|after_or_equal:period_start',
            'scope_type'   => 'nullable|string|max:60',
        ]);

        $data['generated_by'] = Auth::id();

        $report = $this->service->generateTrainingEffectivenessReport($data);

        return redirect()->back()->with('success', "Report \"{$report->title}\" generated.");
    }

    // ── Training costs CRUD ───────────────────────────────────────────────────

    public function costsIndex(Request $request): Response
    {
        $costs   = LmsTrainingCost::with(['course', 'createdBy'])
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->orderByDesc('period_start')
            ->paginate(25)
            ->withQueryString();

        $courses = LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);

        return Inertia::render('Modules/LMS/Admin/EffectivenessDashboard', [
            'costs'     => $costs,
            'courses'   => $courses,
            'filters'   => $request->only(['course_id']),
            'activeTab' => 'costs',
        ]);
    }

    public function storeCost(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'course_id'        => 'required|exists:lms_courses,id',
            'cost_per_employee'=> 'required|numeric|min:0',
            'currency'         => 'nullable|string|max:3',
            'period_start'     => 'nullable|date',
            'period_end'       => 'nullable|date|after_or_equal:period_start',
            'notes'            => 'nullable|string',
        ]);

        $data['created_by'] = Auth::id();
        LmsTrainingCost::create($data);

        return redirect()->back()->with('success', 'Training cost saved.');
    }

    public function updateCost(Request $request, LmsTrainingCost $cost): RedirectResponse
    {
        $data = $request->validate([
            'cost_per_employee'=> 'required|numeric|min:0',
            'currency'         => 'nullable|string|max:3',
            'period_start'     => 'nullable|date',
            'period_end'       => 'nullable|date|after_or_equal:period_start',
            'notes'            => 'nullable|string',
        ]);

        $cost->update($data);

        return redirect()->back()->with('success', 'Training cost updated.');
    }

    public function destroyCost(LmsTrainingCost $cost): RedirectResponse
    {
        $cost->delete();
        return redirect()->back()->with('success', 'Training cost deleted.');
    }
}
