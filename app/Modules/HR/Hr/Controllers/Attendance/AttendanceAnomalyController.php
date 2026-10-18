<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAnomaly;
use App\Modules\HR\Hr\Models\Attendance\AttendanceBehaviorPattern;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRiskScore;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Services\AttendanceAnomalyService;
use App\Modules\HR\Hr\Services\AttendanceIntelligenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AttendanceAnomalyController extends Controller
{
    public function __construct(
        private AttendanceAnomalyService      $anomalyService,
        private AttendanceIntelligenceService $intelligenceService,
    ) {}

    // ── Anomaly list ──────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $status   = $request->input('status', 'open');
        $severity = $request->input('severity');
        $type     = $request->input('type');

        $query = AttendanceAnomaly::with('employee')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->when($severity, fn($q) => $q->where('severity', $severity))
            ->when($type, fn($q) => $q->where('anomaly_type', $type))
            ->latest('detected_at')
            ->paginate(30)
            ->through(fn($a) => [
                'id'           => $a->id,
                'employee'     => $a->employee?->full_name ?? '—',
                'employee_id'  => $a->employee_id,
                'type'         => $a->anomaly_type,
                'severity'     => $a->severity,
                'severity_color' => AttendanceAnomaly::SEVERITY_COLORS[$a->severity] ?? 'grey',
                'period'       => $a->period_start->format('d/m/Y') . ' – ' . $a->period_end->format('d/m/Y'),
                'description'  => $a->description,
                'status'       => $a->status,
                'ai_explanation' => $a->ai_explanation,
                'detected_at'  => $a->detected_at->format('d/m/Y H:i'),
            ]);

        $summary = [
            'open'     => AttendanceAnomaly::where('status', 'open')->count(),
            'critical' => AttendanceAnomaly::where('status', 'open')->where('severity', 'critical')->count(),
            'high'     => AttendanceAnomaly::where('status', 'open')->where('severity', 'high')->count(),
        ];

        return Inertia::render('HR/Attendance/Intelligence/Anomalies', [
            'anomalies' => $query,
            'summary'   => $summary,
            'filters'   => ['status' => $status, 'severity' => $severity, 'type' => $type],
            'types'     => AttendanceAnomaly::TYPES,
            'severities' => AttendanceAnomaly::SEVERITIES,
        ]);
    }

    // ── Run detection scan ────────────────────────────────────────────────────

    public function runScan(Request $request)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'employee_id'  => 'nullable|integer|exists:hr_people,id',
        ]);

        if ($data['employee_id'] ?? null) {
            $anomalies = $this->anomalyService->scanEmployee(
                $data['employee_id'], $data['period_start'], $data['period_end']
            );
            $this->anomalyService->generateAttendanceRiskScore(
                $data['employee_id'], $data['period_start'], $data['period_end']
            );
            $this->anomalyService->buildBehaviorPatterns(
                $data['employee_id'], $data['period_start'], $data['period_end']
            );
            $count = count($anomalies);
        } else {
            $results = $this->anomalyService->runFullScan($data['period_start'], $data['period_end']);
            $count   = array_sum(array_map('count', $results));
        }

        return redirect()->back()->with('success', "Scan complete. {$count} anomaly(ies) detected.");
    }

    // ── Acknowledge anomaly ───────────────────────────────────────────────────

    public function acknowledge(Request $request, AttendanceAnomaly $attendanceAnomaly)
    {
        $attendanceAnomaly->update([
            'status'          => 'acknowledged',
            'acknowledged_by' => Auth::id(),
            'acknowledged_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Anomaly acknowledged.');
    }

    // ── Resolve / mark false positive ─────────────────────────────────────────

    public function resolve(Request $request, AttendanceAnomaly $attendanceAnomaly)
    {
        $data = $request->validate([
            'status'           => 'required|in:resolved,false_positive',
            'resolution_notes' => 'nullable|string',
        ]);

        $attendanceAnomaly->update([
            'status'           => $data['status'],
            'resolution_notes' => $data['resolution_notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Anomaly status updated.');
    }

    // ── AI explain single anomaly ─────────────────────────────────────────────

    public function explain(AttendanceAnomaly $attendanceAnomaly)
    {
        $explanation = $this->intelligenceService->explainAnomaly($attendanceAnomaly);

        return redirect()->back()->with('success', 'AI explanation generated.');
    }

    // ── Risk scores dashboard ─────────────────────────────────────────────────

    public function riskScores(Request $request)
    {
        $scores = AttendanceRiskScore::with('employee')
            ->latest('calculated_at')
            ->paginate(30)
            ->through(fn($s) => [
                'id'              => $s->id,
                'employee'        => $s->employee?->full_name ?? '—',
                'employee_id'     => $s->employee_id,
                'score'           => $s->score,
                'risk_level'      => $s->risk_level,
                'risk_color'      => AttendanceRiskScore::RISK_COLORS[$s->risk_level] ?? 'grey',
                'attendance_rate' => $s->attendance_rate,
                'late_count'      => $s->late_count,
                'absent_count'    => $s->absent_count,
                'anomaly_count'   => $s->anomaly_count,
                'period'          => $s->period_start->format('d/m/Y') . ' – ' . $s->period_end->format('d/m/Y'),
                'calculated_at'   => $s->calculated_at->format('d/m/Y H:i'),
                'ai_summary'      => $s->ai_summary,
            ]);

        $stats = [
            'critical' => AttendanceRiskScore::where('risk_level', 'critical')->latest30Days()->count(),
            'high'     => AttendanceRiskScore::where('risk_level', 'high')->latest30Days()->count(),
            'medium'   => AttendanceRiskScore::where('risk_level', 'medium')->latest30Days()->count(),
            'low'      => AttendanceRiskScore::where('risk_level', 'low')->latest30Days()->count(),
        ];

        return Inertia::render('HR/Attendance/Intelligence/RiskScores', [
            'scores' => $scores,
            'stats'  => $stats,
        ]);
    }

    // ── AI explain risk score ─────────────────────────────────────────────────

    public function explainRisk(AttendanceRiskScore $attendanceRiskScore)
    {
        $this->intelligenceService->explainRiskScore($attendanceRiskScore);

        return redirect()->back()->with('success', 'AI risk summary generated.');
    }

    // ── Behavior patterns ─────────────────────────────────────────────────────

    public function patterns(Request $request)
    {
        $employeeId = $request->input('employee_id');

        $patterns = AttendanceBehaviorPattern::with('employee')
            ->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))
            ->latest('period_start')
            ->paginate(30)
            ->through(fn($p) => [
                'id'           => $p->id,
                'employee'     => $p->employee?->full_name ?? '—',
                'pattern_type' => $p->pattern_type,
                'period_type'  => $p->period_type,
                'period'       => $p->period_start->format('d/m/Y') . ' – ' . $p->period_end->format('d/m/Y'),
                'data'         => $p->data,
                'insight'      => $p->insight,
                'confidence'   => $p->confidence,
            ]);

        return Inertia::render('HR/Attendance/Intelligence/Patterns', [
            'patterns'  => $patterns,
            'employees' => HRPerson::where('status', 1)->orderBy('first_name')->get(['id', 'first_name', 'surname']),
            'filters'   => ['employee_id' => $employeeId],
        ]);
    }

    // ── AI Intelligence Q&A ───────────────────────────────────────────────────

    public function intelligence(Request $request)
    {
        $employees = HRPerson::where('status', 1)->orderBy('first_name')->get(['id', 'first_name', 'surname']);

        return Inertia::render('HR/Attendance/Intelligence/Index', [
            'employees' => $employees,
        ]);
    }

    public function askIntelligence(Request $request)
    {
        $data = $request->validate([
            'employee_id'  => 'required|integer|exists:hr_people,id',
            'question'     => 'required|string|max:500',
            'period_start' => 'required|date',
            'period_end'   => 'required|date',
        ]);

        $answer = $this->intelligenceService->askAboutEmployee(
            $data['employee_id'],
            $data['question'],
            $data['period_start'],
            $data['period_end'],
        );

        return response()->json(['answer' => $answer]);
    }

    public function teamReport(Request $request)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date',
        ]);

        $employeeIds = HRPerson::where('status', 1)->pluck('id')->toArray();
        $report = $this->intelligenceService->generateTeamAttendanceSummary(
            $employeeIds, $data['period_start'], $data['period_end']
        );

        return response()->json(['report' => $report]);
    }
}
