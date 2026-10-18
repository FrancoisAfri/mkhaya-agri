<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDepartmentWellnessScore;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWellnessAlert;
use App\Modules\HR\Hr\Services\AttendanceWellnessSignalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AttendanceWellnessController extends Controller
{
    public function __construct(
        private readonly AttendanceWellnessSignalService $service
    ) {}

    // ── Wellness Alerts Dashboard ─────────────────────────────────────────────

    public function index(Request $request)
    {
        $type     = $request->input('type', 'all');
        $severity = $request->input('severity', 'all');
        $status   = $request->input('status', 'open');

        $query = AttendanceWellnessAlert::with('employee')->orderByDesc('created_at');

        if ($type !== 'all')     $query->where('alert_type', $type);
        if ($severity !== 'all') $query->where('severity', $severity);
        if ($status !== 'all')   $query->where('status', $status);

        $alerts = $query->paginate(20)->through(fn($a) => [
            'id'          => $a->id,
            'employee'    => $a->employee ? "{$a->employee->first_name} {$a->employee->surname}" : '—',
            'employee_id' => $a->employee_id,
            'alert_type'  => $a->alert_type,
            'type_label'  => AttendanceWellnessAlert::TYPES[$a->alert_type]      ?? $a->alert_type,
            'type_icon'   => AttendanceWellnessAlert::TYPE_ICONS[$a->alert_type] ?? 'fas fa-exclamation',
            'severity'    => $a->severity,
            'severity_color' => $a->severity_color,
            'status'      => $a->status,
            'status_color'=> AttendanceWellnessAlert::STATUS_COLORS[$a->status]  ?? 'grey',
            'description' => $a->description,
            'evidence'    => $a->evidence,
            'ai_insight'  => $a->ai_insight,
            'period_start'=> $a->period_start->toDateString(),
            'period_end'  => $a->period_end->toDateString(),
            'created_at'  => $a->created_at->toDateTimeString(),
        ]);

        $summary = [
            'open'     => AttendanceWellnessAlert::where('status', 'open')->count(),
            'critical' => AttendanceWellnessAlert::where('severity', 'critical')->where('status', 'open')->count(),
            'high'     => AttendanceWellnessAlert::where('severity', 'high')->where('status', 'open')->count(),
        ];

        return Inertia::render('HR/Attendance/Wellness/Index', [
            'alerts'   => $alerts,
            'summary'  => $summary,
            'filters'  => ['type' => $type, 'severity' => $severity, 'status' => $status],
            'types'    => AttendanceWellnessAlert::TYPES,
        ]);
    }

    // ── Department Wellness Scores ────────────────────────────────────────────

    public function departmentScores(Request $request)
    {
        $periodStart = $request->input('period_start', now()->subDays(30)->toDateString());
        $periodEnd   = $request->input('period_end',   now()->toDateString());

        $scores = AttendanceDepartmentWellnessScore::where('period_start', '>=', $periodStart)
            ->orderBy('wellness_score')
            ->get()
            ->map(fn($s) => [
                'id'              => $s->id,
                'department'      => $s->department,
                'wellness_score'  => $s->wellness_score,
                'risk_level'      => $s->risk_level,
                'risk_color'      => $s->risk_color,
                'headcount'       => $s->headcount,
                'high_risk_count' => $s->high_risk_count,
                'open_alerts'     => $s->open_alerts_count,
                'avg_absenteeism' => $s->avg_absenteeism_rate,
                'avg_overtime'    => $s->avg_overtime_hours,
                'top_issues'      => $s->top_issues,
                'ai_summary'      => $s->ai_summary,
                'period_start'    => $s->period_start->toDateString(),
                'period_end'      => $s->period_end->toDateString(),
            ]);

        $departments = DB::table('company_units')
            ->where('type', 'department')
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name');

        if ($departments->isEmpty()) {
            $departments = DB::table('hr_people')
                ->whereNotNull('division_level_1')
                ->distinct()
                ->orderBy('division_level_1')
                ->pluck('division_level_1');
        }

        return Inertia::render('HR/Attendance/Wellness/DepartmentScores', [
            'scores'      => $scores,
            'departments' => $departments,
            'filters'     => ['period_start' => $periodStart, 'period_end' => $periodEnd],
        ]);
    }

    // ── Run Wellness Scan ─────────────────────────────────────────────────────

    public function runScan(Request $request)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $results = $this->service->runWellnessScan($data['period_start'], $data['period_end']);

        return redirect()->back()->with('success',
            "{$results['alerts']} wellness alert(s) generated, {$results['departments']} department score(s) updated."
        );
    }

    // ── Acknowledge alert ─────────────────────────────────────────────────────

    public function acknowledge(AttendanceWellnessAlert $attendanceWellnessAlert)
    {
        $attendanceWellnessAlert->update([
            'status'          => 'acknowledged',
            'acknowledged_by' => auth()->id(),
            'acknowledged_at' => now(),
        ]);
        return redirect()->back()->with('success', 'Alert acknowledged.');
    }

    // ── Resolve alert ─────────────────────────────────────────────────────────

    public function resolve(Request $request, AttendanceWellnessAlert $attendanceWellnessAlert)
    {
        $data = $request->validate(['manager_notes' => 'nullable|string|max:1000']);
        $attendanceWellnessAlert->update(['status' => 'resolved', 'manager_notes' => $data['manager_notes'] ?? null]);
        return redirect()->back()->with('success', 'Alert resolved.');
    }

    // ── Dismiss alert ─────────────────────────────────────────────────────────

    public function dismiss(AttendanceWellnessAlert $attendanceWellnessAlert)
    {
        $attendanceWellnessAlert->update(['status' => 'dismissed']);
        return redirect()->back()->with('success', 'Alert dismissed.');
    }

    // ── AI explain single alert ───────────────────────────────────────────────

    public function explainAlert(AttendanceWellnessAlert $attendanceWellnessAlert)
    {
        $insight = $this->service->explainAlert($attendanceWellnessAlert);
        $attendanceWellnessAlert->update(['ai_insight' => $insight]);
        return redirect()->back()->with('success', 'AI insight generated.');
    }

    // ── Manager insights (JSON for inline display) ────────────────────────────

    public function managerInsights(Request $request)
    {
        $data = $request->validate([
            'department'   => 'required|string',
            'period_start' => 'required|date',
            'period_end'   => 'required|date',
        ]);

        $insight = $this->service->generateManagerInsights(
            $data['department'],
            $data['period_start'],
            $data['period_end']
        );

        return response()->json(['insight' => $insight]);
    }
}
