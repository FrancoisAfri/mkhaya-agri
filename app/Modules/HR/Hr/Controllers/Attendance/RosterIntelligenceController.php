<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceShift;
use App\Modules\HR\Hr\Models\Attendance\RosterAssignment;
use App\Modules\HR\Hr\Models\Attendance\RosterOptimizationLog;
use App\Modules\HR\Hr\Models\Attendance\RosterSchedule;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAuditLog;
use App\Modules\HR\Hr\Services\RosterIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RosterIntelligenceController extends Controller
{
    public function __construct(
        private readonly RosterIntelligenceService $service
    ) {}

    // ── Roster list ───────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $status = $request->input('status', 'all');

        $query = RosterSchedule::withCount('assignments')->orderByDesc('created_at');
        if ($status !== 'all') $query->where('status', $status);

        $schedules = $query->paginate(15)->through(fn($s) => [
            'id'                  => $s->id,
            'name'                => $s->name,
            'department'          => $s->department,
            'period_start'        => $s->period_start->toDateString(),
            'period_end'          => $s->period_end->toDateString(),
            'status'              => $s->status,
            'status_color'        => $s->status_color,
            'generated_by'        => $s->generated_by,
            'coverage_score'      => $s->coverage_score,
            'balance_score'       => $s->balance_score,
            'assignments_count'   => $s->assignments_count,
            'published_at'        => $s->published_at?->toDateTimeString(),
        ]);

        $departments = DB::table('hr_people')
            ->whereNotNull('division_level_1')
            ->distinct()
            ->pluck('division_level_1')
            ->sort()
            ->values();

        return Inertia::render('HR/Attendance/Roster/Index', [
            'schedules'   => $schedules,
            'departments' => $departments,
            'filters'     => ['status' => $status],
            'summary'     => [
                'draft'     => RosterSchedule::where('status', 'draft')->count(),
                'published' => RosterSchedule::where('status', 'published')->count(),
                'archived'  => RosterSchedule::where('status', 'archived')->count(),
            ],
        ]);
    }

    // ── Create roster ─────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'department'   => 'nullable|string|max:100',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after:period_start',
        ]);

        $schedule = $this->service->createSchedule($data, auth()->id());

        AttendanceAuditLog::record('roster_created', notes: "Roster '{$schedule->name}' created", auditableType: RosterSchedule::class, auditableId: $schedule->id);

        return redirect()->route('attendance.roster.show', $schedule)
            ->with('success', 'Roster schedule created.');
    }

    // ── View / edit single roster ─────────────────────────────────────────────

    public function show(RosterSchedule $rosterSchedule)
    {
        $assignments = $rosterSchedule->assignments()
            ->with(['employee', 'shift'])
            ->orderBy('assigned_date')
            ->orderBy('employee_id')
            ->get()
            ->map(fn($a) => [
                'id'             => $a->id,
                'employee'       => $a->employee ? "{$a->employee->first_name} {$a->employee->surname}" : "—",
                'employee_id'    => $a->employee_id,
                'shift'          => $a->shift?->name,
                'assigned_date'  => $a->assigned_date->toDateString(),
                'status'         => $a->status,
                'status_color'   => $a->status_color,
                'is_ai_suggested'=> $a->is_ai_suggested,
                'is_rest_day'    => $a->is_rest_day,
                'notes'          => $a->notes,
            ]);

        $logs = $rosterSchedule->optimizationLogs()
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn($l) => [
                'id'                   => $l->id,
                'optimization_type'    => RosterOptimizationLog::TYPES[$l->optimization_type] ?? $l->optimization_type,
                'coverage_before'      => $l->coverage_score_before,
                'coverage_after'       => $l->coverage_score_after,
                'balance_before'       => $l->balance_score_before,
                'balance_after'        => $l->balance_score_after,
                'changes_count'        => count($l->changes_made ?? []),
                'ai_report'            => $l->ai_report,
                'recommendations'      => $l->recommendations,
                'created_at'           => $l->created_at->toDateTimeString(),
            ]);

        $imbalances = $this->service->detectShiftImbalances($rosterSchedule);
        $shifts     = AttendanceShift::select('id', 'name', 'code')->where('status', true)->get();

        return Inertia::render('HR/Attendance/Roster/Schedule', [
            'schedule'    => [
                'id'                    => $rosterSchedule->id,
                'name'                  => $rosterSchedule->name,
                'department'            => $rosterSchedule->department,
                'period_start'          => $rosterSchedule->period_start->toDateString(),
                'period_end'            => $rosterSchedule->period_end->toDateString(),
                'status'                => $rosterSchedule->status,
                'status_color'          => $rosterSchedule->status_color,
                'generated_by'          => $rosterSchedule->generated_by,
                'coverage_score'        => $rosterSchedule->coverage_score,
                'balance_score'         => $rosterSchedule->balance_score,
                'ai_optimization_notes' => $rosterSchedule->ai_optimization_notes,
            ],
            'assignments' => $assignments,
            'logs'        => $logs,
            'imbalances'  => $imbalances,
            'shifts'      => $shifts,
        ]);
    }

    // ── Roster actions ────────────────────────────────────────────────────────

    public function generateAi(RosterSchedule $rosterSchedule)
    {
        $schedule = $this->service->generateAiRoster($rosterSchedule);
        return redirect()->back()->with('success',
            "AI roster generated. Coverage: {$schedule->coverage_score}%, Balance: {$schedule->balance_score}%."
        );
    }

    public function optimizeStaffing(RosterSchedule $rosterSchedule)
    {
        $log = $this->service->optimizeStaffingLevels($rosterSchedule, auth()->id());
        return redirect()->back()->with('success',
            "Staffing optimised. Coverage: {$log->coverage_score_before}% → {$log->coverage_score_after}%."
        );
    }

    public function optimizeDistribution(RosterSchedule $rosterSchedule)
    {
        $log = $this->service->optimizeShiftDistribution($rosterSchedule, auth()->id());
        return redirect()->back()->with('success',
            "Shift distribution optimised. Balance: {$log->balance_score_before}% → {$log->balance_score_after}%."
        );
    }

    public function aiReport(RosterSchedule $rosterSchedule)
    {
        $log = $this->service->generateAiOptimizationReport($rosterSchedule, auth()->id());
        return redirect()->back()->with('success', 'AI optimisation report generated.');
    }

    public function publish(RosterSchedule $rosterSchedule)
    {
        $this->service->publishSchedule($rosterSchedule);
        AttendanceAuditLog::record('roster_published', notes: "Roster '{$rosterSchedule->name}' published", auditableType: RosterSchedule::class, auditableId: $rosterSchedule->id);
        return redirect()->back()->with('success', 'Roster published successfully.');
    }

    public function archive(RosterSchedule $rosterSchedule)
    {
        $rosterSchedule->update(['status' => 'archived']);
        return redirect()->back()->with('success', 'Roster archived.');
    }

    // ── Assignment management ─────────────────────────────────────────────────

    public function updateAssignment(Request $request, RosterAssignment $rosterAssignment)
    {
        $data = $request->validate([
            'shift_id'   => 'nullable|exists:attendance_shifts,id',
            'status'     => 'nullable|in:scheduled,confirmed,swapped,cancelled,absent',
            'is_rest_day'=> 'nullable|boolean',
            'notes'      => 'nullable|string|max:500',
        ]);

        $old = $rosterAssignment->only(['shift_id', 'status', 'is_rest_day', 'notes']);
        $rosterAssignment->update($data);
        AttendanceAuditLog::record('roster_assignment_changed', $rosterAssignment->employee_id, $old, $data, null, RosterAssignment::class, $rosterAssignment->id);
        return redirect()->back()->with('success', 'Assignment updated.');
    }
}
