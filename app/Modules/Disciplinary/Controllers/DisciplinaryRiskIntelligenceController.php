<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DepartmentBehaviorRisk;
use App\Modules\Disciplinary\Models\DisciplinaryHeatmap;
use App\Modules\Disciplinary\Models\DisciplinaryRiskPrediction;
use App\Modules\Disciplinary\Services\RiskIntelligenceService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryRiskIntelligenceController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(private readonly RiskIntelligenceService $service) {}

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $periodEnd   = $request->get('period_end', now()->toDateString());
        $periodStart = $request->get('period_start', now()->subMonths(3)->toDateString());

        $predictions = DisciplinaryRiskPrediction::with('employee.person')
            ->when($request->risk_level, fn($q, $l) => $q->where('risk_level', $l))
            ->when(!$request->show_actioned, fn($q) => $q->active())
            ->orderByDesc('probability')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($p) => $this->mapPrediction($p));

        $deptRisks = DepartmentBehaviorRisk::with('department')
            ->where('period_end', '<=', $periodEnd)
            ->where('period_start', '>=', $periodStart)
            ->highRisk()
            ->orderByDesc('risk_score')
            ->get()
            ->map(fn($d) => [
                'department'   => $d->department?->name ?? 'Unknown',
                'total_cases'  => $d->total_cases,
                'risk_score'   => $d->risk_score,
                'risk_level'   => $d->risk_level,
                'trend'        => $d->trend,
                'ai_narrative' => $d->ai_narrative,
            ]);

        $heatmaps = DisciplinaryHeatmap::orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($h) => [
                'id'           => $h->id,
                'type'         => $h->heatmap_type,
                'title'        => $h->title,
                'period_start' => $h->period_start?->format('d M Y'),
                'period_end'   => $h->period_end?->format('d M Y'),
                'data_points'  => $h->data_points,
                'legend'       => $h->legend,
            ]);

        $stats = [
            'critical_predictions' => DisciplinaryRiskPrediction::where('risk_level', 'critical')->active()->count(),
            'high_predictions'     => DisciplinaryRiskPrediction::where('risk_level', 'high')->active()->count(),
            'actioned'             => DisciplinaryRiskPrediction::where('actioned', true)->count(),
            'dept_high_risk'       => DepartmentBehaviorRisk::highRisk()->count(),
            'pending_predictions'  => DisciplinaryRiskPrediction::active()->count(),
        ];

        $employees = User::join('hr_people as p', 'p.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email']);

        $departments = CompanyUnit::where('type', 'department')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Modules/Disciplinary/RiskIntelligence/Index', [
            'predictions'  => $predictions,
            'dept_risks'   => $deptRisks,
            'heatmaps'     => $heatmaps,
            'stats'        => $stats,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'employees'    => $employees,
            'departments'  => $departments,
        ]);
    }

    // ── Employee risk prediction ───────────────────────────────────────────────

    public function predictEmployee(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'    => 'required|exists:users,id',
            'prediction_type'=> 'nullable|in:repeat_offence,escalation,dismissal_risk,suspension_risk,behavioral_decline',
        ]);

        $type = $data['prediction_type'] ?? 'behavioral_decline';

        $prediction = $type === 'dismissal_risk'
            ? $this->service->predictDismissalRisk($data['employee_id'], auth()->id())
            : $this->service->detectHighRiskEmployee($data['employee_id'], auth()->id());

        return redirect()->back()->with([
            'success'            => "Risk prediction generated.",
            'prediction_result'  => [
                'employee'            => $prediction->employee?->person?->full_name ?? $prediction->employee?->email,
                'prediction_type'     => $prediction->prediction_type,
                'risk_level'          => $prediction->risk_level,
                'probability'         => round($prediction->probability, 1),
                'reasoning'           => $prediction->reasoning,
                'recommended_actions' => $prediction->recommended_actions ?? [],
            ],
        ]);
    }

    public function actionPrediction(Request $request, DisciplinaryRiskPrediction $prediction): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $prediction->update([
            'actioned'    => true,
            'actioned_by' => auth()->id(),
            'actioned_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Risk prediction marked as actioned.');
    }

    // ── Department risk ───────────────────────────────────────────────────────

    public function analyzeDepartment(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'department_id' => 'required|exists:company_units,id',
            'period_start'  => 'required|date',
            'period_end'    => 'required|date|after:period_start',
        ]);

        $risk = $this->service->detectDepartmentRiskTrend(
            $data['department_id'],
            $data['period_start'],
            $data['period_end'],
            auth()->id(),
        );

        return redirect()->back()->with('success', "Department risk analyzed — level: {$risk->risk_level}");
    }

    // ── Heatmap generation ────────────────────────────────────────────────────

    public function generateHeatmap(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'heatmap_type'  => 'required|in:department_risk,offence_category,monthly_trend',
            'period_start'  => 'nullable|date',
            'period_end'    => 'nullable|date',
            'year'          => 'nullable|integer|min:2020|max:2030',
        ]);

        $heatmap = match ($data['heatmap_type']) {
            'department_risk'  => $this->service->predictDisciplinaryHotspot(
                $data['period_start'] ?? now()->startOfYear()->toDateString(),
                $data['period_end']   ?? now()->toDateString(),
                auth()->id(),
            ),
            'offence_category' => $this->service->generateOffenceCategoryHeatmap(
                $data['period_start'] ?? now()->startOfYear()->toDateString(),
                $data['period_end']   ?? now()->toDateString(),
                auth()->id(),
            ),
            'monthly_trend'    => $this->service->generateMonthlyTrendHeatmap(
                $data['year'] ?? now()->year,
                auth()->id(),
            ),
        };

        return redirect()->back()->with('success', "Heatmap generated: {$heatmap->title}");
    }

    // ── Map helper ────────────────────────────────────────────────────────────

    private function mapPrediction(DisciplinaryRiskPrediction $p): array
    {
        return [
            'id'                    => $p->id,
            'employee_id'           => $p->employee_id,
            'employee'              => $p->employee->person?->full_name ?? $p->employee?->email,
            'prediction_type'       => $p->prediction_type,
            'probability'           => $p->probability,
            'risk_level'            => $p->risk_level,
            'prediction_window_end' => $p->prediction_window_end?->format('d M Y'),
            'reasoning'             => $p->reasoning,
            'contributing_factors'  => $p->contributing_factors,
            'recommended_actions'   => $p->recommended_actions,
            'actioned'              => $p->actioned,
            'is_ai_generated'       => $p->is_ai_generated,
            'created_at'            => $p->created_at?->format('d M Y'),
        ];
    }
}
