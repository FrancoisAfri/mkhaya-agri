<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceComplianceScore;
use App\Modules\HR\Hr\Models\Attendance\AttendancePolicyException;
use App\Modules\HR\Hr\Models\Attendance\AttendancePolicyRule;
use App\Modules\HR\Hr\Models\Attendance\AttendancePolicyViolation;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAuditLog;
use App\Modules\HR\Hr\Services\AttendancePolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendancePolicyController extends Controller
{
    public function __construct(
        private readonly AttendancePolicyService $policyService
    ) {}

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $this->policyService->seedDefaultRules();

        $openViolations = AttendancePolicyViolation::with(['employee', 'policyRule'])
            ->whereIn('status', ['open', 'escalated'])
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn($v) => $this->formatViolation($v));

        $rules = AttendancePolicyRule::all()->map(fn($r) => [
            'id'             => $r->id,
            'code'           => $r->code,
            'name'           => $r->name,
            'category'       => $r->category,
            'category_label' => AttendancePolicyRule::CATEGORIES[$r->category] ?? $r->category,
            'threshold'      => $r->threshold,
            'threshold_unit' => $r->threshold_unit,
            'period_days'    => $r->period_days,
            'severity'       => $r->severity,
            'severity_color' => $r->severity_color,
            'action'         => $r->action,
            'action_label'   => AttendancePolicyRule::ACTIONS[$r->action] ?? $r->action,
            'auto_escalate'  => $r->auto_escalate,
            'active'         => $r->active,
        ]);

        $summary = [
            'open'      => AttendancePolicyViolation::where('status', 'open')->count(),
            'escalated' => AttendancePolicyViolation::where('status', 'escalated')->count(),
            'resolved'  => AttendancePolicyViolation::where('status', 'resolved')->count(),
            'blocked'   => AttendancePolicyViolation::where('blocked', true)->count(),
        ];

        return Inertia::render('HR/Attendance/Policy/Index', [
            'recentViolations' => $openViolations,
            'rules'            => $rules,
            'summary'          => $summary,
        ]);
    }

    // ── Violations list ───────────────────────────────────────────────────────

    public function violations(Request $request): Response
    {
        $status     = $request->input('status', 'all');
        $category   = $request->input('category', 'all');

        $query = AttendancePolicyViolation::with(['employee', 'policyRule'])
            ->orderByDesc('created_at');

        if ($status !== 'all') $query->where('status', $status);
        if ($category !== 'all') {
            $query->whereHas('policyRule', fn($q) => $q->where('category', $category));
        }

        $violations = $query->paginate(20)->through(fn($v) => $this->formatViolation($v));

        return Inertia::render('HR/Attendance/Policy/Violations', [
            'violations' => $violations,
            'filters'    => ['status' => $status, 'category' => $category],
            'categories' => AttendancePolicyRule::CATEGORIES,
        ]);
    }

    // ── Compliance scores ─────────────────────────────────────────────────────

    public function compliance(Request $request): Response
    {
        $scores = AttendanceComplianceScore::with('employee')
            ->orderByDesc('total_score')
            ->paginate(20)
            ->through(fn($s) => [
                'id'               => $s->id,
                'employee'         => $s->employee ? "{$s->employee->first_name} {$s->employee->surname}" : '—',
                'period_start'     => $s->period_start->toDateString(),
                'period_end'       => $s->period_end->toDateString(),
                'total_score'      => $s->total_score,
                'score_color'      => AttendanceComplianceScore::colorFromScore($s->total_score),
                'score_label'      => AttendanceComplianceScore::labelFromScore($s->total_score),
                'lateness_score'   => $s->lateness_score,
                'overtime_score'   => $s->overtime_score,
                'attendance_score' => $s->attendance_score,
                'adherence_score'  => $s->adherence_score,
                'violations_count' => $s->violations_count,
                'exceptions_count' => $s->exceptions_count,
                'ai_summary'       => $s->ai_summary,
                'computed_at'      => $s->computed_at->toDateTimeString(),
            ]);

        $averageScore = AttendanceComplianceScore::avg('total_score') ?? 0;

        return Inertia::render('HR/Attendance/Policy/Compliance', [
            'scores'       => $scores,
            'averageScore' => round($averageScore, 1),
        ]);
    }

    // ── Exceptions list ───────────────────────────────────────────────────────

    public function exceptions(): Response
    {
        $exceptions = AttendancePolicyException::with(['employee', 'policyRule', 'grantedBy'])
            ->orderByDesc('approved_at')
            ->paginate(20)
            ->through(fn($e) => [
                'id'          => $e->id,
                'employee'    => $e->employee ? "{$e->employee->first_name} {$e->employee->surname}" : '—',
                'policy_rule' => $e->policyRule?->name,
                'reason'      => $e->reason,
                'granted_by'  => $e->grantedBy?->name,
                'approved_at' => $e->approved_at->toDateString(),
                'expires_at'  => $e->expires_at?->toDateString(),
                'is_expired'  => $e->is_expired,
                'notes'       => $e->notes,
            ]);

        return Inertia::render('HR/Attendance/Policy/Exceptions', [
            'exceptions' => $exceptions,
        ]);
    }

    // ── Run policy scan ───────────────────────────────────────────────────────

    public function runScan(Request $request): RedirectResponse
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $results = $this->policyService->runPolicyScan(
            $request->period_start,
            $request->period_end,
            auth()->id()
        );

        return back()->with('success', "{$results['violations']} violation(s) detected, {$results['blocked']} blocked.");
    }

    // ── Generate compliance scores ────────────────────────────────────────────

    public function generateCompliance(Request $request): RedirectResponse
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $employees = \Illuminate\Support\Facades\DB::table('hr_people')
            ->where('status', 1)
            ->pluck('id');

        foreach ($employees as $empId) {
            $this->policyService->generateComplianceScore($empId, $request->period_start, $request->period_end);
        }

        return redirect()->route('attendance.policy.compliance')->with('success', "Compliance scores generated for {$employees->count()} employee(s).");
    }

    // ── Escalate violation ────────────────────────────────────────────────────

    public function escalate(AttendancePolicyViolation $attendancePolicyViolation): RedirectResponse
    {
        $attendancePolicyViolation->update([
            'status'       => 'escalated',
            'escalated_at' => now(),
        ]);

        AttendanceAuditLog::record('policy_violation_escalated', $attendancePolicyViolation->employee_id, [], [], null, AttendancePolicyViolation::class, $attendancePolicyViolation->id);

        return back()->with('success', 'Violation escalated.');
    }

    // ── Resolve violation ─────────────────────────────────────────────────────

    public function resolve(Request $request, AttendancePolicyViolation $attendancePolicyViolation): RedirectResponse
    {
        $request->validate(['resolution_notes' => 'required|string|max:1000']);

        $attendancePolicyViolation->update([
            'status'           => 'resolved',
            'resolved_at'      => now(),
            'resolution_notes' => $request->resolution_notes,
        ]);

        AttendanceAuditLog::record('policy_violation_resolved', $attendancePolicyViolation->employee_id, [], ['resolution_notes' => $request->resolution_notes], null, AttendancePolicyViolation::class, $attendancePolicyViolation->id);

        return back()->with('success', 'Violation resolved.');
    }

    // ── Grant exception ───────────────────────────────────────────────────────

    public function grantException(Request $request, AttendancePolicyViolation $attendancePolicyViolation): RedirectResponse
    {
        $request->validate([
            'reason'     => 'required|string|max:1000',
            'expires_at' => 'nullable|date|after:today',
            'notes'      => 'nullable|string|max:1000',
        ]);

        AttendancePolicyException::create([
            'violation_id'    => $attendancePolicyViolation->id,
            'employee_id'     => $attendancePolicyViolation->employee_id,
            'policy_rule_id'  => $attendancePolicyViolation->policy_rule_id,
            'reason'          => $request->reason,
            'granted_by'      => auth()->id(),
            'approved_at'     => now(),
            'expires_at'      => $request->expires_at,
            'notes'           => $request->notes,
        ]);

        $attendancePolicyViolation->update(['status' => 'excepted']);

        return back()->with('success', 'Exception granted.');
    }

    // ── AI enforcement decision ───────────────────────────────────────────────

    public function generateAiEnforcement(AttendancePolicyViolation $attendancePolicyViolation): \Illuminate\Http\JsonResponse
    {
        $decision = $this->policyService->generateAiEnforcement($attendancePolicyViolation);

        $attendancePolicyViolation->update(['ai_enforcement_decision' => $decision]);

        return response()->json(['decision' => $decision]);
    }

    // ── AI compliance summary ─────────────────────────────────────────────────

    public function generateComplianceSummary(AttendanceComplianceScore $attendanceComplianceScore): \Illuminate\Http\JsonResponse
    {
        $summary = $this->policyService->generateComplianceSummary($attendanceComplianceScore);

        $attendanceComplianceScore->update(['ai_summary' => $summary]);

        return response()->json(['summary' => $summary]);
    }

    // ── Toggle rule active state ──────────────────────────────────────────────

    public function toggleRule(AttendancePolicyRule $attendancePolicyRule): RedirectResponse
    {
        $attendancePolicyRule->update(['active' => ! $attendancePolicyRule->active]);

        $state = $attendancePolicyRule->active ? 'activated' : 'deactivated';
        return back()->with('success', "Policy rule '{$attendancePolicyRule->name}' {$state}.");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function formatViolation(AttendancePolicyViolation $v): array
    {
        return [
            'id'                      => $v->id,
            'employee'                => $v->employee ? "{$v->employee->first_name} {$v->employee->surname}" : '—',
            'employee_id'             => $v->employee_id,
            'policy_rule'             => $v->policyRule?->name,
            'category'                => $v->policyRule?->category,
            'category_label'          => AttendancePolicyRule::CATEGORIES[$v->policyRule?->category] ?? '—',
            'severity'                => $v->policyRule?->severity,
            'severity_color'          => $v->policyRule ? $v->policyRule->severity_color : 'secondary',
            'action'                  => $v->policyRule?->action,
            'detected_value'          => $v->detected_value,
            'threshold_value'         => $v->threshold_value,
            'status'                  => $v->status,
            'status_color'            => $v->status_color,
            'blocked'                 => $v->blocked,
            'ai_enforcement_decision' => $v->ai_enforcement_decision,
            'period_start'            => $v->period_start->toDateString(),
            'period_end'              => $v->period_end->toDateString(),
            'escalated_at'            => $v->escalated_at?->toDateString(),
            'resolved_at'             => $v->resolved_at?->toDateString(),
            'resolution_notes'        => $v->resolution_notes,
            'created_at'              => $v->created_at->toDateTimeString(),
        ];
    }
}
