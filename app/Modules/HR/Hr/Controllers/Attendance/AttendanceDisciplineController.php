<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDisciplineCase;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDisciplineOffender;
use App\Modules\HR\Hr\Models\Attendance\AttendanceDisciplinePack;
use App\Modules\HR\Hr\Services\AttendanceDisciplineService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceDisciplineController extends Controller
{
    public function __construct(
        private readonly AttendanceDisciplineService $service
    ) {}

    // ── Discipline Cases List ─────────────────────────────────────────────────

    public function index(Request $request)
    {
        $status   = $request->input('status', 'all');
        $caseType = $request->input('case_type', 'all');

        $query = AttendanceDisciplineCase::with('employee')->orderByDesc('created_at');

        if ($status   !== 'all') $query->where('status', $status);
        if ($caseType !== 'all') $query->where('case_type', $caseType);

        $cases = $query->paginate(15)->through(fn($c) => [
            'id'               => $c->id,
            'employee'         => $c->employee ? "{$c->employee->first_name} {$c->employee->surname}" : '—',
            'employee_id'      => $c->employee_id,
            'case_number'      => $c->case_number,
            'case_type'        => $c->case_type,
            'case_type_label'  => AttendanceDisciplineCase::CASE_TYPES[$c->case_type] ?? $c->case_type,
            'status'           => $c->status,
            'status_color'     => $c->status_color,
            'severity'         => $c->severity,
            'severity_color'   => $c->severity_color,
            'summary'          => $c->summary,
            'violations'       => $c->violations,
            'ai_recommendation'=> $c->ai_recommendation,
            'period_start'     => $c->period_start->toDateString(),
            'period_end'       => $c->period_end->toDateString(),
            'has_pack'         => $c->packs()->exists(),
            'created_at'       => $c->created_at->toDateTimeString(),
        ]);

        $summary = [
            'open'   => AttendanceDisciplineCase::open()->count(),
            'pending'=> AttendanceDisciplineCase::pending()->count(),
        ];

        return Inertia::render('HR/Attendance/Discipline/Index', [
            'cases'     => $cases,
            'summary'   => $summary,
            'filters'   => ['status' => $status, 'case_type' => $caseType],
            'caseTypes' => AttendanceDisciplineCase::CASE_TYPES,
        ]);
    }

    // ── Case Detail ───────────────────────────────────────────────────────────

    public function show(AttendanceDisciplineCase $attendanceDisciplineCase)
    {
        $attendanceDisciplineCase->load('employee', 'packs');

        $pack = $attendanceDisciplineCase->packs()->latest()->first();

        return Inertia::render('HR/Attendance/Discipline/CaseDetail', [
            'disciplineCase' => [
                'id'               => $attendanceDisciplineCase->id,
                'employee'         => $attendanceDisciplineCase->employee
                    ? "{$attendanceDisciplineCase->employee->first_name} {$attendanceDisciplineCase->employee->surname}"
                    : '—',
                'case_number'      => $attendanceDisciplineCase->case_number,
                'case_type'        => $attendanceDisciplineCase->case_type,
                'case_type_label'  => AttendanceDisciplineCase::CASE_TYPES[$attendanceDisciplineCase->case_type] ?? $attendanceDisciplineCase->case_type,
                'status'           => $attendanceDisciplineCase->status,
                'status_color'     => $attendanceDisciplineCase->status_color,
                'severity'         => $attendanceDisciplineCase->severity,
                'severity_color'   => $attendanceDisciplineCase->severity_color,
                'summary'          => $attendanceDisciplineCase->summary,
                'violations'       => $attendanceDisciplineCase->violations,
                'ai_recommendation'=> $attendanceDisciplineCase->ai_recommendation,
                'resolution_notes' => $attendanceDisciplineCase->resolution_notes,
                'period_start'     => $attendanceDisciplineCase->period_start->toDateString(),
                'period_end'       => $attendanceDisciplineCase->period_end->toDateString(),
                'created_at'       => $attendanceDisciplineCase->created_at->toDateTimeString(),
            ],
            'pack' => $pack ? [
                'id'                    => $pack->id,
                'attendance_summary'    => $pack->attendance_summary,
                'violation_details'     => $pack->violation_details,
                'ai_narrative'          => $pack->ai_narrative,
                'warning_letter_content'=> $pack->warning_letter_content,
                'recommended_sanction'  => $pack->recommended_sanction,
                'supporting_references' => $pack->supporting_references,
                'generated_at'          => $pack->generated_at->toDateTimeString(),
            ] : null,
        ]);
    }

    // ── Repeat Offenders ──────────────────────────────────────────────────────

    public function offenders(Request $request)
    {
        $level = $request->input('level', 'all');

        $query = AttendanceDisciplineOffender::with('employee')->orderByDesc('total_violations');
        if ($level !== 'all') $query->where('offender_level', $level);

        $offenders = $query->paginate(20)->through(fn($o) => [
            'id'                   => $o->id,
            'employee'             => $o->employee ? "{$o->employee->first_name} {$o->employee->surname}" : '—',
            'employee_id'          => $o->employee_id,
            'total_violations'     => $o->total_violations,
            'late_count'           => $o->late_count,
            'absent_count'         => $o->absent_count,
            'no_show_count'        => $o->no_show_count,
            'early_departure_count'=> $o->early_departure_count,
            'prior_warnings'       => $o->prior_warnings,
            'offender_level'       => $o->offender_level,
            'level_label'          => AttendanceDisciplineOffender::LEVELS[$o->offender_level] ?? $o->offender_level,
            'offender_color'       => $o->offender_color,
            'first_offence_date'   => $o->first_offence_date?->toDateString(),
            'last_offence_date'    => $o->last_offence_date?->toDateString(),
            'period_start'         => $o->period_start->toDateString(),
            'period_end'           => $o->period_end->toDateString(),
            'violation_breakdown'  => $o->violation_breakdown,
            'ai_risk_assessment'   => $o->ai_risk_assessment,
            'assessed_at'          => $o->assessed_at->toDateTimeString(),
        ]);

        return Inertia::render('HR/Attendance/Discipline/Offenders', [
            'offenders' => $offenders,
            'filters'   => ['level' => $level],
            'summary'   => [
                'repeat'   => AttendanceDisciplineOffender::where('offender_level', 'repeat')->count(),
                'serious'  => AttendanceDisciplineOffender::where('offender_level', 'serious')->count(),
                'moderate' => AttendanceDisciplineOffender::where('offender_level', 'moderate')->count(),
                'minor'    => AttendanceDisciplineOffender::where('offender_level', 'minor')->count(),
            ],
        ]);
    }

    // ── Run Discipline Scan ───────────────────────────────────────────────────

    public function runScan(Request $request)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $results = $this->service->runDisciplineScan($data['period_start'], $data['period_end'], auth()->id());

        return redirect()->back()->with('success',
            "{$results['cases']} discipline case(s) created, {$results['offenders']} offender profile(s) updated."
        );
    }

    // ── Generate disciplinary pack ────────────────────────────────────────────

    public function generatePack(AttendanceDisciplineCase $attendanceDisciplineCase)
    {
        $this->service->generateDisciplinaryPack($attendanceDisciplineCase, auth()->id());
        return redirect()->back()->with('success', 'Disciplinary pack generated.');
    }

    // ── Generate AI recommendation ────────────────────────────────────────────

    public function generateRecommendation(AttendanceDisciplineCase $attendanceDisciplineCase)
    {
        $recommendation = $this->service->generateWarningRecommendation($attendanceDisciplineCase);
        $attendanceDisciplineCase->update(['ai_recommendation' => $recommendation]);
        return redirect()->back()->with('success', 'AI recommendation generated.');
    }

    // ── Resolve case ──────────────────────────────────────────────────────────

    public function resolve(Request $request, AttendanceDisciplineCase $attendanceDisciplineCase)
    {
        $data = $request->validate([
            'status'           => 'required|in:resolved,closed,dismissed',
            'resolution_notes' => 'nullable|string|max:2000',
        ]);

        $attendanceDisciplineCase->update([
            'status'           => $data['status'],
            'resolution_notes' => $data['resolution_notes'] ?? null,
            'resolved_at'      => now(),
        ]);

        return redirect()->back()->with('success', 'Case updated.');
    }
}
