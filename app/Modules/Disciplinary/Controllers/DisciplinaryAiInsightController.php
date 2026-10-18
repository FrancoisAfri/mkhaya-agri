<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryAiSummary;
use App\Modules\Disciplinary\Models\DisciplinaryBehaviorPattern;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryRiskScore;
use App\Modules\Disciplinary\Services\DisciplinaryAiService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryAiInsightController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(protected DisciplinaryAiService $ai) {}

    // ── Main AI insights dashboard ─────────────────────────────────────────────

    public function index(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        // Risk score leaderboard
        $riskScores = DisciplinaryRiskScore::with('employee.person')
            ->orderByDesc('risk_score')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'employee_id'     => $r->employee_id,
                'employee_name'   => $this->resolveEmployeeName($r->employee),
                'risk_score'      => round($r->risk_score, 1),
                'risk_level'      => $r->risk_level,
                'risk_color'      => $r->riskColor(),
                'total_cases'     => $r->total_cases,
                'is_repeat'       => $r->is_repeat_offender,
                'last_case_date'  => $r->last_case_date?->format('d M Y'),
                'analyzed_at'     => $r->analyzed_at?->format('d M Y H:i'),
            ]);

        // Recent behavior patterns
        $patterns = DisciplinaryBehaviorPattern::with('employee.person')
            ->where('acknowledged', false)
            ->orderByDesc('detected_at')
            ->limit(30)
            ->get()
            ->map(fn($p) => [
                'id'           => $p->id,
                'employee_id'  => $p->employee_id,
                'employee_name'=> $this->resolveEmployeeName($p->employee),
                'pattern_type' => $p->pattern_type,
                'pattern_label'=> $p->pattern_label,
                'description'  => $p->pattern_description,
                'severity'     => $p->severity,
                'severity_color'=> $p->severityColor(),
                'icon'         => DisciplinaryBehaviorPattern::patternIcon($p->pattern_type),
                'detected_at'  => $p->detected_at?->format('d M Y'),
            ]);

        // Department trend data
        $departmentTrends = $this->ai->detectDepartmentTrends();

        // Summary stats
        $stats = [
            'critical_risk'   => DisciplinaryRiskScore::where('risk_level', 'critical')->count(),
            'high_risk'       => DisciplinaryRiskScore::where('risk_level', 'high')->count(),
            'repeat_offenders'=> DisciplinaryRiskScore::where('is_repeat_offender', true)->count(),
            'active_patterns' => DisciplinaryBehaviorPattern::where('acknowledged', false)->count(),
            'total_analyzed'  => DisciplinaryRiskScore::count(),
        ];

        return Inertia::render('Modules/Disciplinary/AiInsights/Index', [
            'riskScores'       => $riskScores,
            'patterns'         => $patterns,
            'departmentTrends' => $departmentTrends,
            'stats'            => $stats,
        ]);
    }

    // ── Run full analysis for all employees with cases ─────────────────────────

    public function runAnalysis(): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $employeeIds = DB::table('disciplinary_cases')
            ->distinct()
            ->pluck('employee_id');

        $analyzed = 0;
        foreach ($employeeIds as $id) {
            try {
                $this->ai->analyzeEmployee($id);
                $analyzed++;
            } catch (\Throwable $e) {
                // Log and continue
            }
        }

        return back()->with('success', "Analysis complete. {$analyzed} employee(s) scored.");
    }

    // ── Generate AI summary for a specific case ────────────────────────────────

    public function generateCaseSummary(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->ai->generateCaseSummary($case);

        return back()->with('success', 'AI case summary generated.');
    }

    // ── Generate sanction recommendation for a case ────────────────────────────

    public function generateSanctionRecommendation(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->ai->generateSanctionRecommendation($case);

        return back()->with('success', 'Sanction recommendation generated.');
    }

    // ── Acknowledge a behavior pattern ─────────────────────────────────────────

    public function acknowledgePattern(Request $request, DisciplinaryBehaviorPattern $pattern): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $pattern->update([
            'acknowledged'    => true,
            'acknowledged_by' => auth()->id(),
            'acknowledged_at' => now(),
        ]);

        return back()->with('success', 'Pattern acknowledged.');
    }

    // ── Get AI summaries for a specific case (JSON) ────────────────────────────

    public function caseSummaries(DisciplinaryCase $case): JsonResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $summaries = DisciplinaryAiSummary::where('subject_type', 'case')
            ->where('subject_id', $case->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($s) => [
                'id'             => $s->id,
                'summary_type'   => $s->summaryTypeLabel(),
                'summary'        => $s->summary,
                'recommendation' => $s->recommendation,
                'ai_model'       => $s->ai_model,
                'created_at'     => $s->created_at?->format('d M Y H:i'),
            ]);

        return response()->json($summaries);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function resolveEmployeeName(?User $user): string
    {
        if (! $user) return 'Unknown';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? 'Unknown');
    }
}
