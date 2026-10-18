<?php

namespace App\Modules\HR\Hr\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWellnessScore;
use App\Modules\HR\Hr\Models\Attendance\BurnoutRiskProfile;
use App\Modules\HR\Hr\Models\Attendance\WorkforcePressurePrediction;
use App\Modules\HR\Hr\Services\WorkforcePressureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WorkforcePressureController extends Controller
{
    public function __construct(
        private readonly WorkforcePressureService $service
    ) {}

    // ── Wellness Dashboard ────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $periodStart = $request->input('period_start', now()->subDays(30)->toDateString());
        $periodEnd   = $request->input('period_end',   now()->toDateString());

        $scores = AttendanceWellnessScore::with('employee')
            ->where('period_start', '>=', $periodStart)
            ->orderBy('wellness_score')
            ->paginate(20)
            ->through(fn($s) => [
                'id'               => $s->id,
                'employee'         => $s->employee ? "{$s->employee->first_name} {$s->employee->surname}" : "—",
                'employee_id'      => $s->employee_id,
                'wellness_score'   => $s->wellness_score,
                'burnout_risk'     => $s->burnout_risk,
                'risk_color'       => $s->risk_color,
                'fatigue_level'    => $s->fatigue_level,
                'total_overtime_h' => $s->total_overtime_hours,
                'consecutive_days' => $s->consecutive_days_worked,
                'late_count'       => $s->late_arrivals_count,
                'absenteeism_pct'  => $s->absenteeism_rate,
                'period_start'     => $s->period_start->toDateString(),
                'period_end'       => $s->period_end->toDateString(),
                'ai_summary'       => $s->ai_summary,
                'score_breakdown'  => $s->score_breakdown,
            ]);

        $stats = [
            'critical' => AttendanceWellnessScore::where('burnout_risk', 'critical')->count(),
            'high'     => AttendanceWellnessScore::where('burnout_risk', 'high')->count(),
            'medium'   => AttendanceWellnessScore::where('burnout_risk', 'medium')->count(),
            'low'      => AttendanceWellnessScore::where('burnout_risk', 'low')->count(),
        ];

        $predictions = WorkforcePressurePrediction::active()
            ->orderByDesc('confidence')
            ->take(8)
            ->get()
            ->map(fn($p) => [
                'id'             => $p->id,
                'type'           => $p->prediction_type,
                'type_label'     => WorkforcePressurePrediction::TYPES[$p->prediction_type] ?? $p->prediction_type,
                'scope'          => $p->scope,
                'department'     => $p->department,
                'employee'       => $p->employee ? "{$p->employee->first_name} {$p->employee->surname}" : null,
                'confidence'     => $p->confidence,
                'risk_level'     => $p->risk_level,
                'risk_color'     => $p->risk_color,
                'predicted_for'  => $p->predicted_for->toDateString(),
                'evidence'       => $p->evidence,
                'ai_explanation' => $p->ai_explanation,
                'status'         => $p->status,
            ]);

        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->select('id', 'first_name', 'surname')
            ->orderBy('first_name')
            ->get();

        return Inertia::render('HR/Attendance/Workforce/Index', [
            'scores'      => $scores,
            'stats'       => $stats,
            'predictions' => $predictions,
            'employees'   => $employees,
            'filters'     => ['period_start' => $periodStart, 'period_end' => $periodEnd],
        ]);
    }

    // ── Burnout Profiles ──────────────────────────────────────────────────────

    public function burnoutProfiles(Request $request)
    {
        $risk = $request->input('risk', 'all');

        $query = BurnoutRiskProfile::with('employee')->orderByDesc('assessed_at');

        if ($risk !== 'all') {
            $query->where('overall_risk', $risk);
        }

        $profiles = $query->paginate(15)->through(fn($p) => [
            'id'                      => $p->id,
            'employee'                => $p->employee ? "{$p->employee->first_name} {$p->employee->surname}" : "—",
            'employee_id'             => $p->employee_id,
            'overall_risk'            => $p->overall_risk,
            'risk_color'              => $p->risk_color,
            'composite_score'         => $p->composite_score,
            'overtime_overload_score' => $p->overtime_overload_score,
            'consecutive_work_score'  => $p->consecutive_work_score,
            'absence_pattern_score'   => $p->absence_pattern_score,
            'late_arrival_score'      => $p->late_arrival_score,
            'shift_instability_score' => $p->shift_instability_score,
            'risk_factors'            => $p->risk_factors,
            'recommendations'         => $p->recommendations,
            'ai_narrative'            => $p->ai_narrative,
            'period_start'            => $p->period_start->toDateString(),
            'period_end'              => $p->period_end->toDateString(),
            'assessed_at'             => $p->assessed_at->toDateTimeString(),
        ]);

        return Inertia::render('HR/Attendance/Workforce/BurnoutProfiles', [
            'profiles' => $profiles,
            'filters'  => ['risk' => $risk],
            'summary'  => [
                'critical' => BurnoutRiskProfile::where('overall_risk', 'critical')->count(),
                'high'     => BurnoutRiskProfile::where('overall_risk', 'high')->count(),
                'medium'   => BurnoutRiskProfile::where('overall_risk', 'medium')->count(),
                'low'      => BurnoutRiskProfile::where('overall_risk', 'low')->count(),
            ],
        ]);
    }

    // ── Predictions ───────────────────────────────────────────────────────────

    public function predictions(Request $request)
    {
        $type   = $request->input('type', 'all');
        $status = $request->input('status', 'active');

        $query = WorkforcePressurePrediction::with('employee')
            ->orderByDesc('confidence');

        if ($type !== 'all')   $query->where('prediction_type', $type);
        if ($status !== 'all') $query->where('status', $status);

        $predictions = $query->paginate(20)->through(fn($p) => [
            'id'             => $p->id,
            'type'           => $p->prediction_type,
            'type_label'     => WorkforcePressurePrediction::TYPES[$p->prediction_type] ?? $p->prediction_type,
            'scope'          => $p->scope,
            'department'     => $p->department,
            'employee'       => $p->employee ? "{$p->employee->first_name} {$p->employee->surname}" : null,
            'confidence'     => $p->confidence,
            'risk_level'     => $p->risk_level,
            'risk_color'     => $p->risk_color,
            'predicted_for'  => $p->predicted_for->toDateString(),
            'evidence'       => $p->evidence,
            'ai_explanation' => $p->ai_explanation,
            'status'         => $p->status,
        ]);

        return Inertia::render('HR/Attendance/Workforce/Predictions', [
            'predictions' => $predictions,
            'filters'     => ['type' => $type, 'status' => $status],
            'types'       => WorkforcePressurePrediction::TYPES,
        ]);
    }

    // ── Run Pressure Scan ─────────────────────────────────────────────────────

    public function runScan(Request $request)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $results = $this->service->runPressureScan($data['period_start'], $data['period_end']);

        return redirect()->back()->with('success',
            "Pressure scan complete. {$results['wellness_scores']} wellness scores calculated, " .
            "{$results['burnout_profiles']} burnout profiles generated, " .
            "{$results['predictions']} predictions created."
        );
    }

    // ── AI narrative for single burnout profile ────────────────────────────────

    public function generateNarrative(BurnoutRiskProfile $burnoutRiskProfile)
    {
        $narrative = $this->service->generateBurnoutNarrative($burnoutRiskProfile);
        $burnoutRiskProfile->update(['ai_narrative' => $narrative]);

        return redirect()->back()->with('success', 'AI narrative generated.');
    }

    // ── Dismiss a prediction ──────────────────────────────────────────────────

    public function dismissPrediction(WorkforcePressurePrediction $workforcePressurePrediction)
    {
        $workforcePressurePrediction->update(['status' => 'dismissed']);
        return redirect()->back()->with('success', 'Prediction dismissed.');
    }

    // ── Workforce wellness AI summary ─────────────────────────────────────────

    public function wellnessSummary(Request $request)
    {
        $data    = $request->validate(['period_start' => 'required|date', 'period_end' => 'required|date']);
        $summary = $this->service->generateWellnessSummary($data['period_start'], $data['period_end']);

        return response()->json(['summary' => $summary]);
    }
}
