<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Core\Position\Models\HrPosition;
use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use App\Modules\HR\LMS\Models\LmsComplianceEscalation;
use App\Modules\HR\LMS\Models\LmsComplianceReportSnapshot;
use App\Modules\HR\LMS\Models\LmsComplianceRule;
use App\Modules\HR\LMS\Services\LmsComplianceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LmsComplianceController
{
    public function __construct(private LmsComplianceService $service) {}

    // ── Rules ────────────────────────────────────────────────────────────────

    public function rulesIndex(Request $request): Response
    {
        $rules = LmsComplianceRule::with(['course', 'createdBy'])
            ->withCount(['assignments', 'assignments as overdue_count' => fn ($q) => $q->where('status', 'overdue')])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%' . $request->input('search') . '%'))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $courses   = \App\Modules\HR\LMS\Models\LmsCourse::where('is_published', true)->orderBy('title')->get(['id', 'title']);
        $positions = HrPosition::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Modules/LMS/Admin/ComplianceRules', [
            'rules'       => $rules,
            'courses'     => $courses,
            'positions'   => $positions,
            'scopeTypes'  => LmsComplianceRule::SCOPE_TYPES,
            'frequencies' => LmsComplianceRule::FREQUENCIES,
            'filters'     => $request->only(['search']),
        ]);
    }

    public function storeRule(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'course_id'            => 'required|exists:lms_courses,id',
            'name'                 => 'required|string|max:160',
            'description'          => 'nullable|string',
            'scope_type'           => 'required|in:all,position,department,location,employee',
            'scope_id'             => 'nullable|integer',
            'scope_value'          => 'nullable|string',
            'frequency'            => 'required|in:once,annual,biannual,quarterly,custom',
            'frequency_days'       => 'nullable|integer|min:1',
            'due_within_days'      => 'integer|min:1',
            'grace_period_days'    => 'integer|min:0',
            'reminder_days_before' => 'nullable|array',
            'escalate_after_days'  => 'integer|min:0',
            'escalate_to_role'     => 'in:manager,hr,admin',
            'restrict_actions'     => 'nullable|array',
            'is_active'            => 'boolean',
        ]);

        $data['created_by'] = $request->user()->id;
        LmsComplianceRule::create($data);
        return back()->with('success', 'Compliance rule created.');
    }

    public function updateRule(Request $request, LmsComplianceRule $rule): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'course_id'            => 'required|exists:lms_courses,id',
            'name'                 => 'required|string|max:160',
            'description'          => 'nullable|string',
            'scope_type'           => 'required|in:all,position,department,location,employee',
            'scope_id'             => 'nullable|integer',
            'scope_value'          => 'nullable|string',
            'frequency'            => 'required|in:once,annual,biannual,quarterly,custom',
            'frequency_days'       => 'nullable|integer|min:1',
            'due_within_days'      => 'integer|min:1',
            'grace_period_days'    => 'integer|min:0',
            'reminder_days_before' => 'nullable|array',
            'escalate_after_days'  => 'integer|min:0',
            'escalate_to_role'     => 'in:manager,hr,admin',
            'restrict_actions'     => 'nullable|array',
            'is_active'            => 'boolean',
        ]);

        $rule->update($data);
        return back()->with('success', 'Rule updated.');
    }

    public function destroyRule(LmsComplianceRule $rule): \Illuminate\Http\RedirectResponse
    {
        $rule->delete();
        return back()->with('success', 'Rule deleted.');
    }

    public function assignRule(Request $request, LmsComplianceRule $rule): \Illuminate\Http\RedirectResponse
    {
        $count = $this->service->assignMandatoryTraining($rule);
        return back()->with('success', "{$count} assignment(s) created.");
    }

    // ── Dashboard ────────────────────────────────────────────────────────────

    public function dashboard(Request $request): Response
    {
        $filters = $request->only(['position_id', 'period_start', 'period_end', 'status']);

        // KPI tiles
        $totalActive  = LmsComplianceAssignment::whereIn('status', ['pending', 'in_progress', 'overdue'])->count();
        $totalOverdue = LmsComplianceAssignment::where('status', 'overdue')->count();
        $totalDone    = LmsComplianceAssignment::where('status', 'completed')->count();
        $totalAll     = LmsComplianceAssignment::whereIn('status', ['pending', 'in_progress', 'overdue', 'completed'])->count();
        $complianceRate = $totalAll > 0 ? round(($totalDone / $totalAll) * 100, 1) : 0;

        // Overdue assignments list (paginated)
        $overdueQuery = LmsComplianceAssignment::with(['user.person', 'course', 'rule'])
            ->where('status', 'overdue')
            ->orderBy('due_date');

        if ($request->filled('position_id')) {
            $overdueQuery->whereHas('user.person', fn ($q) => $q->where('position_id', $request->integer('position_id')));
        }

        $overdue = $overdueQuery->paginate(20)->withQueryString();

        // Open escalations
        $escalations = LmsComplianceEscalation::with(['user.person', 'assignment.course', 'escalatedTo.person'])
            ->where('status', 'open')
            ->orderByDesc('escalated_at')
            ->limit(20)
            ->get()
            ->map(fn ($e) => [
                'id'              => $e->id,
                'assignment_id'   => $e->assignment_id,
                'employee_name'   => $this->personName($e->user),
                'course_title'    => $e->assignment?->course?->title,
                'days_overdue'    => $e->days_overdue,
                'escalated_to'    => $this->personName($e->escalatedTo),
                'escalated_at'    => $e->escalated_at?->toDateString(),
                'status'          => $e->status,
            ]);

        // Per-rule compliance rates
        $byRule = LmsComplianceRule::active()
            ->withCount([
                'assignments',
                'assignments as completed_count' => fn ($q) => $q->where('status', 'completed'),
                'assignments as overdue_count'   => fn ($q) => $q->where('status', 'overdue'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'name'           => $r->name,
                'course_title'   => $r->course?->title ?? '—',
                'total'          => $r->assignments_count,
                'completed'      => $r->completed_count,
                'overdue'        => $r->overdue_count,
                'rate'           => $r->assignments_count > 0
                    ? round(($r->completed_count / $r->assignments_count) * 100, 0)
                    : 0,
            ]);

        // Recent reports
        $reports = LmsComplianceReportSnapshot::orderByDesc('generated_at')
            ->limit(10)
            ->get(['id', 'report_type', 'scope_name', 'compliance_rate', 'total_assignments', 'overdue_count', 'generated_at']);

        $positions = HrPosition::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Modules/LMS/Admin/ComplianceDashboard', [
            'kpi'            => compact('totalActive', 'totalOverdue', 'totalDone', 'complianceRate'),
            'overdue'        => $overdue,
            'escalations'    => $escalations,
            'byRule'         => $byRule,
            'reports'        => $reports,
            'positions'      => $positions,
            'filters'        => $filters,
        ]);
    }

    public function generateReport(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'report_type'  => 'required|in:overall,position,department,individual',
            'scope_id'     => 'nullable|integer',
            'period_start' => 'nullable|date',
            'period_end'   => 'nullable|date',
        ]);

        $this->service->generateTrainingComplianceReport(
            $data['report_type'],
            $data['scope_id'] ?? null,
            $data['period_start'] ? \Carbon\Carbon::parse($data['period_start']) : null,
            $data['period_end']   ? \Carbon\Carbon::parse($data['period_end']) : null,
            $request->user()->id
        );

        return back()->with('success', 'Compliance report generated.');
    }

    public function runChecks(Request $request): \Illuminate\Http\RedirectResponse
    {
        $overdue    = $this->service->trackOverdueTraining();
        $reminders  = $this->service->sendTrainingReminders();
        $escalations= $this->service->escalateOverdueTraining();

        return back()->with('success', "Checks complete: {$overdue} overdue marked, {$reminders} reminders sent, {$escalations} escalations created.");
    }

    // ── Assignment actions ────────────────────────────────────────────────────

    public function waveAssignment(Request $request, LmsComplianceAssignment $assignment): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => 'required|string']);
        $this->service->waiveAssignment($assignment, $request->user()->id, $request->input('reason'));
        return back()->with('success', 'Assignment waived.');
    }

    public function acknowledgeEscalation(Request $request, LmsComplianceEscalation $escalation): \Illuminate\Http\RedirectResponse
    {
        $this->service->acknowledgeEscalation($escalation, $request->user()->id);
        return back()->with('success', 'Escalation acknowledged.');
    }

    // ── Compliance check (API endpoint) ──────────────────────────────────────

    public function checkAction(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['action_key' => 'required|string']);
        $blocked = $this->service->blockRestrictedActionIfTrainingMissing(
            $request->user()->id,
            $request->input('action_key')
        );
        $blockedActions = $blocked ? $this->service->getBlockedActions($request->user()->id) : [];

        return response()->json(['blocked' => $blocked, 'blocked_actions' => $blockedActions]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function personName($user): string
    {
        if (!$user) return '—';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : $user->email;
    }
}
