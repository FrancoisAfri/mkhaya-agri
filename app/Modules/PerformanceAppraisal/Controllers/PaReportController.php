<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Exports\PaReportExport;
use App\Modules\PerformanceAppraisal\Models\PaScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class PaReportController extends Controller
{
    // ── Report keys → human labels ────────────────────────────────────────────

    private function availableReportKeys(): array
    {
        return [
            'distribution'    => 'Performance Distribution',
            'high_performers' => 'High Performers',
            'low_performers'  => 'Low Performers',
            'pip'             => 'PIP Employees',
            'promotion_ready' => 'Promotion-Ready Employees',
            'burnout_risk'    => 'Burnout Risk',
            'dept_scores'     => 'Department Performance Scores',
            'bonus'           => 'Bonus Eligibility',
            'calibration_risk'=> 'Calibration Risk',
            'policy_violations'=> 'Policy Violations',
            'kpi_completion'  => 'KPI Completion Rate',
            'feedback_summary'=> 'Feedback Summary',
            'pip_outcomes'    => 'PIP Outcomes',
            'probation'       => 'Probation Reviews',
        ];
    }

    // ── Main reports page ─────────────────────────────────────────────────────

    public function reports(): Response
    {
        $cycles = DB::table('pa_cycles')->orderByDesc('start_date')->get(['id', 'name']);

        $departments = DB::table('company_units')
            ->where('active', true)
            ->where('type', 'department')
            ->orderBy('name')
            ->get(['id', 'name']);

        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->orderByRaw("TRIM(CONCAT(first_name, ' ', surname))")
            ->get(['user_id as id', DB::raw("TRIM(CONCAT(first_name, ' ', surname)) as name")])
            ->map(fn($e) => ['id' => $e->id, 'name' => $e->name])
            ->values();

        // ── Pre-load data for each report section ─────────────────────────────

        $distribution = $this->queryDistribution();
        $highPerformers = $this->queryHighPerformers();
        $lowPerformers  = $this->queryLowPerformers();
        $pipEmployees   = $this->queryPipEmployees();
        $promotionReady = $this->queryPromotionReady();
        $burnoutRisk    = $this->queryBurnoutRisk();
        $deptScores     = $this->queryDeptScores();
        $bonusEligible  = $this->queryBonusEligibility();
        $calibRisk      = $this->queryCalibrationRisk();
        $policyViolations = $this->queryPolicyViolations();
        $kpiCompletion  = $this->queryKpiCompletion();
        $feedbackSummary = $this->queryFeedbackSummary();
        $pipOutcomes    = $this->queryPipOutcomes();
        $probationReviews = $this->queryProbationReviews();

        return Inertia::render('Modules/PerformanceAppraisal/Reports/Index', [
            'distribution'     => $distribution,
            'highPerformers'   => $highPerformers,
            'lowPerformers'    => $lowPerformers,
            'pip'              => $pipEmployees,
            'promotionReady'   => $promotionReady,
            'burnoutRisk'      => $burnoutRisk,
            'deptScores'       => $deptScores,
            'bonus'            => $bonusEligible,
            'calibrationRisk'  => $calibRisk,
            'policyViolations' => $policyViolations,
            'kpiCompletion'    => $kpiCompletion,
            'feedbackSummary'  => $feedbackSummary,
            'pipOutcomes'      => $pipOutcomes,
            'probation'        => $probationReviews,
            'cycles'           => $cycles,
            'departments'      => $departments,
            'employees'        => $employees,
        ]);
    }

    // ── AJAX: row count for a report key ──────────────────────────────────────

    public function generateReport(string $key): JsonResponse
    {
        $count = match ($key) {
            'distribution'     => DB::table('pa_kpi_scores')->count(),
            'high_performers'  => DB::getSchemaBuilder()->hasTable('pa_talent_profiles') ? DB::table('pa_talent_profiles')->where('talent_tier', 'top_talent')->count() : 0,
            'low_performers'   => DB::table('pa_kpi_scores')->where('score_pct', '<', 40)->distinct()->count('employee_kpi_id'),
            'pip'              => DB::table('pa_pip_plans')->whereIn('status', ['active', 'extended'])->count(),
            'promotion_ready'  => DB::getSchemaBuilder()->hasTable('pa_talent_profiles') ? DB::table('pa_talent_profiles')->where('promotion_readiness_score', '>=', 70)->count() : 0,
            'burnout_risk'     => DB::getSchemaBuilder()->hasTable('pa_wellness_scores') ? DB::table('pa_wellness_scores')->where('burnout_risk', 'high')->count() : 0,
            'dept_scores'      => DB::table('pa_kpi_scores')->distinct()->count('employee_kpi_id'),
            'bonus'            => DB::getSchemaBuilder()->hasTable('pa_talent_profiles') ? DB::table('pa_talent_profiles')->where('composite_score', '>=', 75)->count() : 0,
            'calibration_risk' => DB::table('pa_calibration_participants')->whereNull('calibrated_score')->count(),
            'policy_violations'=> DB::getSchemaBuilder()->hasTable('pa_policy_violations') ? DB::table('pa_policy_violations')->where('status', 'open')->count() : 0,
            'kpi_completion'   => DB::table('pa_employee_kpis')->count(),
            'feedback_summary' => DB::table('pa_feedback')->count(),
            'pip_outcomes'     => DB::table('pa_pip_plans')->whereIn('status', ['closed_improved', 'closed_not_improved', 'escalated'])->count(),
            'probation'        => DB::getSchemaBuilder()->hasTable('pa_probation_reviews') ? DB::table('pa_probation_reviews')->count() : 0,
            default            => 0,
        };

        return response()->json(['key' => $key, 'count' => $count]);
    }

    // ── AJAX: queue background report ─────────────────────────────────────────

    public function queueReport(Request $request, string $key): JsonResponse
    {
        $request->validate(['email' => 'nullable|email']);
        // In production: dispatch a job here
        return response()->json(['queued' => true, 'key' => $key]);
    }

    // ── Export (PDF / Excel / CSV) ────────────────────────────────────────────

    public function exportReport(string $key, string $format): mixed
    {
        [$headers, $rows] = $this->buildReportData($key);
        $filename = "performance-{$key}-" . now()->format('Y-m-d');

        if ($format === 'xlsx') {
            return Excel::download(
                new PaReportExport($headers, $rows),
                "{$filename}.xlsx",
            );
        }

        if ($format === 'pdf') {
            $title = $this->availableReportKeys()[$key] ?? ucfirst($key);
            $pdf = Pdf::loadView('reports.pa-report', [
                'title'   => $title,
                'headers' => $headers,
                'rows'    => $rows,
                // The view prints this twice, under the name it uses. It was passed as `date`,
                // so every PDF export died on an undefined variable rather than producing a file.
                'generatedAt' => now()->format('d M Y H:i'),
            ])->setPaper('a4', 'landscape');
            return $pdf->download("{$filename}.pdf");
        }

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv']);
    }

    // ── Scheduled reports ─────────────────────────────────────────────────────

    public function scheduledReports(): Response
    {
        $reports = PaScheduledReport::orderBy('cadence')->orderBy('name')->get()
            ->map(fn($r) => $this->formatScheduledReport($r));

        return Inertia::render('Modules/PerformanceAppraisal/Reports/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => $this->availableReportKeys(),
        ]);
    }

    public function storeScheduledReport(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'report_key'   => 'required|string|in:' . implode(',', array_keys($this->availableReportKeys())),
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string|max:500',
            'active'       => 'boolean',
        ]);

        $report              = new PaScheduledReport($data);
        $report->created_by  = Auth::id();
        $report->next_run_at = $report->computeNextRun();
        $report->save();

        return back()->with('success', 'Scheduled report created.');
    }

    public function updateScheduledReport(Request $request, PaScheduledReport $scheduledReport): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'report_key'   => 'required|string|in:' . implode(',', array_keys($this->availableReportKeys())),
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string|max:500',
            'active'       => 'boolean',
        ]);

        $scheduledReport->fill($data);
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroyScheduledReport(PaScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->delete();
        return back()->with('success', 'Scheduled report deleted.');
    }

    // ── Report data builders ──────────────────────────────────────────────────

    public function buildReportData(string $key): array
    {
        return match ($key) {
            'distribution' => [
                ['Band', 'Count', 'Percentage %'],
                $this->queryDistribution()->map(fn($r) => [$r['band'], $r['count'], $r['percentage']])->toArray(),
            ],
            'high_performers' => [
                ['Employee', 'Department', 'Score', 'Tier', 'Cycle', 'Ranked At'],
                $this->queryHighPerformers()->map(fn($r) => [$r['employee_name'], $r['department'], $r['score'], $r['talent_tier'], $r['cycle'], $r['ranked_at']])->toArray(),
            ],
            'low_performers' => [
                ['Employee', 'Department', 'Score %', 'Cycle', 'Has PIP'],
                $this->queryLowPerformers()->map(fn($r) => [$r['employee_name'], $r['department'], $r['score'], $r['cycle'], $r['has_pip'] ? 'Yes' : 'No'])->toArray(),
            ],
            'pip' => [
                ['Employee', 'Department', 'Manager', 'Status', 'Start Date', 'End Date'],
                $this->queryPipEmployees()->map(fn($r) => [$r['employee_name'], $r['department'], $r['manager'], $r['status'], $r['start_date'], $r['end_date']])->toArray(),
            ],
            'promotion_ready' => [
                ['Employee', 'Department', 'Score', 'Readiness Level', 'Flagged'],
                $this->queryPromotionReady()->map(fn($r) => [$r['employee_name'], $r['department'], $r['score'], $r['readiness_level'], $r['created_at']])->toArray(),
            ],
            'burnout_risk' => [
                ['Employee', 'Department', 'Risk Level', 'Wellness Score', 'Signals', 'Recorded'],
                $this->queryBurnoutRisk()->map(fn($r) => [$r['employee_name'], $r['department'], $r['burnout_risk'], $r['wellness_score'], $r['signal_count'], $r['created_at']])->toArray(),
            ],
            'dept_scores' => [
                ['Department', 'Employees', 'Avg KPI Score %', 'Cycle'],
                $this->queryDeptScores()->map(fn($r) => [$r['department'], $r['employee_count'], $r['avg_score'], $r['cycle']])->toArray(),
            ],
            'bonus' => [
                ['Employee', 'Department', 'Score', 'Cycle'],
                $this->queryBonusEligibility()->map(fn($r) => [$r['employee_name'], $r['department'], $r['score'], $r['cycle']])->toArray(),
            ],
            'calibration_risk' => [
                ['Employee', 'Session', 'Original Score', 'Calibrated Score', 'Adjustment'],
                $this->queryCalibrationRisk()->map(fn($r) => [$r['employee_name'], $r['session'], $r['original_score'], $r['calibrated_score'], $r['adjustment']])->toArray(),
            ],
            'policy_violations' => [
                ['Employee', 'Policy', 'Severity', 'Status', 'Date'],
                $this->queryPolicyViolations()->map(fn($r) => [$r['employee_name'], $r['policy'], $r['severity'], $r['status'], $r['created_at']])->toArray(),
            ],
            'kpi_completion' => [
                ['Employee', 'KPI', 'Target', 'Actual', 'Score %', 'Cycle'],
                $this->queryKpiCompletion()->map(fn($r) => [$r['employee_name'], $r['kpi_name'], $r['target_value'], $r['actual_value'], $r['score'], $r['cycle']])->toArray(),
            ],
            'feedback_summary' => [
                ['Employee', 'Department', 'Praise', 'Coaching', 'Concerns', 'Total'],
                $this->queryFeedbackSummary()->map(fn($r) => [$r['employee_name'], $r['department'], $r['praise_count'], $r['coaching_count'], $r['concern_count'], $r['total_count']])->toArray(),
            ],
            'pip_outcomes' => [
                ['Employee', 'Department', 'Outcome', 'Start Date', 'End Date', 'Duration (days)'],
                $this->queryPipOutcomes()->map(fn($r) => [$r['employee_name'], $r['department'], $r['outcome'], $r['start_date'], $r['end_date'], $r['duration_days']])->toArray(),
            ],
            'probation' => [
                ['Employee', 'Department', 'Decision', 'Risk Score', 'Review Date', 'End Date'],
                $this->queryProbationReviews()->map(fn($r) => [$r['employee_name'], $r['department'], $r['decision'], $r['risk_score'], $r['review_date'], $r['probation_end_date']])->toArray(),
            ],
            default => [['Key'], [[$key]]],
        };
    }

    // ── Executive Graph Dashboard ────────────────────────────────────────────

    public function executiveDashboard(): Response
    {
        $totalEmployees = DB::table('hr_people')->where('status', 1)->count();
        $avgScore       = round((float) (DB::table('pa_kpi_scores')->avg('score_pct') ?? 0), 1);

        $scoreCounts = DB::table('pa_kpi_scores')
            ->selectRaw("SUM(CASE WHEN score_pct >= 80 THEN 1 ELSE 0 END) as high, SUM(CASE WHEN score_pct < 40 THEN 1 ELSE 0 END) as low, COUNT(*) as total")
            ->first();

        $kpis = [
            'total_employees'    => $totalEmployees,
            'avg_score'          => $avgScore,
            'high_performers'    => $scoreCounts?->high ?? 0,
            'low_performers'     => $scoreCounts?->low  ?? 0,
            'kpi_completion_pct' => $scoreCounts?->total > 0 ? round(($scoreCounts->high / $scoreCounts->total) * 100, 1) : 0,
            'active_pips'        => DB::table('pa_pip_plans')->whereIn('status', ['active', 'extended'])->count(),
            'burnout_risks'      => DB::getSchemaBuilder()->hasTable('pa_wellness_scores') ? DB::table('pa_wellness_scores')->whereIn('burnout_risk', ['high', 'critical'])->count() : 0,
            'open_violations'    => DB::getSchemaBuilder()->hasTable('pa_policy_violations') ? DB::table('pa_policy_violations')->where('status', 'open')->count() : 0,
        ];

        // Score distribution bands
        $distribution = $this->queryDistribution()->values();

        // Dept avg scores (top 12)
        $deptScores = $this->queryDeptScores()->take(12)->values();

        // Cycle avg score trend
        $cycleTrend = DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->join('pa_cycles as c', 'c.id', '=', 'k.cycle_id')
            ->selectRaw("c.name as cycle, ROUND(AVG(s.score_pct),1) as avg_score, COUNT(DISTINCT k.employee_id) as participants")
            ->groupBy('c.id', 'c.name')
            ->orderBy('c.id')
            ->limit(8)
            ->get();

        // PIP status breakdown
        $pipByStatus = DB::table('pa_pip_plans')
            ->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => ucfirst(str_replace('_', ' ', $r->status)), 'count' => $r->count]);

        // Feedback type distribution
        $feedbackByType = DB::table('pa_feedback')
            ->selectRaw("feedback_type, COUNT(*) as count")
            ->groupBy('feedback_type')
            ->get()
            ->map(fn($r) => ['type' => ucfirst($r->feedback_type ?? 'Other'), 'count' => $r->count]);

        // KPI completion status
        $kpiByStatus = DB::table('pa_employee_kpis')
            ->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => ucfirst(str_replace('_', ' ', $r->status ?? 'unknown')), 'count' => $r->count]);

        // Top 10 employees by avg score
        $topEmployees = DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->selectRaw("k.employee_id, ROUND(AVG(s.score_pct),1) as avg_score")
            ->groupBy('k.employee_id')
            ->orderByRaw('AVG(s.score_pct) desc')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'name'  => $this->resolveEmployeeName($r->employee_id),
                'score' => $r->avg_score,
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/Reports/ExecutiveDashboard', [
            'kpis'          => $kpis,
            'distribution'  => $distribution,
            'deptScores'    => $deptScores,
            'cycleTrend'    => $cycleTrend,
            'pipByStatus'   => $pipByStatus,
            'feedbackByType'=> $feedbackByType,
            'kpiByStatus'   => $kpiByStatus,
            'topEmployees'  => $topEmployees,
        ]);
    }

    private function resolveEmployeeName(int $id): string
    {
        return $this->tenantPersonName($id);
    }

    private function resolveDepartment(int $id): string
    {
        static $cache = [];
        if (isset($cache[$id])) return $cache[$id];
        $row = DB::table('company_unit_user_pivots as cup')
            ->join('company_units as cu', 'cu.id', '=', 'cup.company_id')
            ->where('cup.user_id', $id)
            ->where('cu.type', 'department')
            ->where('cu.active', true)
            ->select('cu.name')
            ->first();
        return $cache[$id] = ($row?->name ?? '—');
    }

    // ── Individual query methods ──────────────────────────────────────────────

    private function queryDistribution(): \Illuminate\Support\Collection
    {
        $scores = DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->selectRaw("k.employee_id, ROUND(AVG(s.score_pct),1) as avg_score")
            ->groupBy('k.employee_id', 'k.cycle_id')
            ->get();

        $total = $scores->count();
        if ($total === 0) return collect();

        $bands = [
            ['band' => 'Outstanding (80–100)', 'min' => 80,  'max' => 100],
            ['band' => 'Good (60–79)',          'min' => 60,  'max' => 79.99],
            ['band' => 'Meets (40–59)',         'min' => 40,  'max' => 59.99],
            ['band' => 'Below (20–39)',         'min' => 20,  'max' => 39.99],
            ['band' => 'Poor (0–19)',           'min' => 0,   'max' => 19.99],
        ];

        return collect($bands)->map(function ($band) use ($scores, $total) {
            $count = $scores->filter(fn($r) => $r->avg_score >= $band['min'] && $r->avg_score <= $band['max'])->count();
            return [
                'band'       => $band['band'],
                'count'      => $count,
                'percentage' => round($count / $total * 100, 1),
            ];
        });
    }

    private function queryHighPerformers(): \Illuminate\Support\Collection
    {
        if (!DB::getSchemaBuilder()->hasTable('pa_talent_profiles')) return collect();

        $cyclesById = DB::table('pa_cycles')->pluck('name', 'id')->all();

        return DB::table('pa_talent_profiles')
            ->whereIn('talent_tier', ['top_talent', 'high_performer'])
            ->orderByDesc('composite_score')
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'department'    => $this->resolveDepartment($r->employee_id),
                'score'         => $r->composite_score,
                'talent_tier'   => $r->talent_tier,
                'cycle'         => isset($r->cycle_id) ? ($cyclesById[$r->cycle_id] ?? '—') : '—',
                'ranked_at'     => isset($r->created_at) ? \Carbon\Carbon::parse($r->created_at)->format('Y-m-d') : null,
            ]);
    }

    private function queryLowPerformers(): \Illuminate\Support\Collection
    {
        $pipEmpIds = DB::table('pa_pip_plans')
            ->whereIn('status', ['active', 'extended'])
            ->pluck('employee_id')
            ->all();
        $pipSet = array_flip($pipEmpIds);

        return DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->join('pa_cycles as c', 'c.id', '=', 'k.cycle_id')
            ->selectRaw("k.employee_id, c.name as cycle, ROUND(AVG(s.score_pct),1) as avg_score")
            ->groupBy('k.employee_id', 'c.name', 'c.id')
            ->havingRaw('AVG(s.score_pct) < 40')
            ->orderByRaw('AVG(s.score_pct) asc')
            ->limit(100)
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'department'    => $this->resolveDepartment($r->employee_id),
                'score'         => $r->avg_score,
                'cycle'         => $r->cycle,
                'has_pip'       => isset($pipSet[$r->employee_id]),
            ]);
    }

    private function queryPipEmployees(): \Illuminate\Support\Collection
    {
        return DB::table('pa_pip_plans')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'department'    => $this->resolveDepartment($r->employee_id),
                'manager'       => $this->resolveEmployeeName($r->manager_id),
                'status'        => $r->status,
                'start_date'    => $r->start_date,
                'end_date'      => $r->target_end_date,
            ]);
    }

    private function queryPromotionReady(): \Illuminate\Support\Collection
    {
        if (!DB::getSchemaBuilder()->hasTable('pa_talent_profiles')) return collect();

        return DB::table('pa_talent_profiles')
            ->where('promotion_readiness_score', '>=', 60)
            ->orderByDesc('promotion_readiness_score')
            ->get()
            ->map(fn($r) => [
                'employee_name'   => $this->resolveEmployeeName($r->employee_id),
                'department'      => $this->resolveDepartment($r->employee_id),
                'score'           => $r->promotion_readiness_score,
                'readiness_level' => $r->promotion_readiness_level ?? '—',
                'created_at'      => isset($r->created_at) ? \Carbon\Carbon::parse($r->created_at)->format('Y-m-d') : null,
            ]);
    }

    private function queryBurnoutRisk(): \Illuminate\Support\Collection
    {
        if (!DB::getSchemaBuilder()->hasTable('pa_wellness_scores')) return collect();

        return DB::table('pa_wellness_scores')
            ->whereIn('burnout_risk', ['high', 'critical'])
            ->orderByDesc('wellness_score')
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'department'    => $this->resolveDepartment($r->employee_id),
                'burnout_risk'  => $r->burnout_risk,
                'wellness_score'=> $r->wellness_score,
                'signal_count'  => $r->signals ? count((array) json_decode($r->signals, true)) : 0,
                'created_at'    => isset($r->created_at) ? \Carbon\Carbon::parse($r->created_at)->format('Y-m-d') : null,
            ]);
    }

    private function queryDeptScores(): \Illuminate\Support\Collection
    {
        return DB::table('pa_kpi_scores as s')
            ->join('pa_employee_kpis as k', 'k.id', '=', 's.employee_kpi_id')
            ->join('pa_cycles as c', 'c.id', '=', 'k.cycle_id')
            ->join('hr_people as p', 'p.user_id', '=', 'k.employee_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as cid FROM company_unit_user_pivots GROUP BY user_id) as cuup'),
                'cuup.user_id', '=', 'p.user_id'
            )
            ->leftJoin('company_units as cu', 'cu.id', '=', DB::raw('COALESCE(p.division_level_1::bigint, cuup.cid)'))
            ->selectRaw("COALESCE(cu.name,'Unknown') as department, c.name as cycle, ROUND(AVG(s.score_pct),1) as avg_score, COUNT(DISTINCT k.employee_id) as employee_count")
            ->groupBy('cu.name', 'c.name', 'c.id')
            ->orderBy('c.id', 'desc')
            ->orderByRaw('AVG(s.score_pct) desc')
            ->get()
            ->map(fn($r) => [
                'department'     => $r->department,
                'cycle'          => $r->cycle,
                'avg_score'      => $r->avg_score,
                'employee_count' => $r->employee_count,
            ]);
    }

    private function queryBonusEligibility(): \Illuminate\Support\Collection
    {
        if (!DB::getSchemaBuilder()->hasTable('pa_talent_profiles')) return collect();

        $cyclesById = DB::table('pa_cycles')->pluck('name', 'id')->all();

        return DB::table('pa_talent_profiles')
            ->where('composite_score', '>=', 75)
            ->orderByDesc('composite_score')
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'department'    => $this->resolveDepartment($r->employee_id),
                'score'         => $r->composite_score,
                'cycle'         => isset($r->cycle_id) ? ($cyclesById[$r->cycle_id] ?? '—') : '—',
            ]);
    }

    private function queryCalibrationRisk(): \Illuminate\Support\Collection
    {
        return DB::table('pa_calibration_participants as cp')
            ->join('pa_calibration_sessions as cs', 'cs.id', '=', 'cp.session_id')
            ->selectRaw("cp.employee_id, cs.title as session, cp.original_score, cp.calibrated_score, cp.adjustment_reason, (COALESCE(cp.calibrated_score,0) - COALESCE(cp.original_score,0)) as adjustment")
            ->orderByRaw('ABS(COALESCE(cp.calibrated_score,0) - COALESCE(cp.original_score,0)) desc')
            ->limit(100)
            ->get()
            ->map(fn($r) => [
                'employee_name'    => $this->resolveEmployeeName($r->employee_id),
                'session'          => $r->session,
                'original_score'   => $r->original_score,
                'calibrated_score' => $r->calibrated_score ?? '—',
                'adjustment'       => $r->adjustment,
                'adjustment_reason'=> $r->adjustment_reason ?? '—',
            ]);
    }

    private function queryPolicyViolations(): \Illuminate\Support\Collection
    {
        if (!DB::getSchemaBuilder()->hasTable('pa_policy_violations')) return collect();

        return DB::table('pa_policy_violations as v')
            ->leftJoin('pa_policies as pol', 'pol.id', '=', 'v.policy_id')
            ->selectRaw("v.employee_id, pol.name as violation_type, v.action_type, v.status, v.created_at")
            ->orderByDesc('v.created_at')
            ->limit(100)
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'policy'        => $r->violation_type ?? '—',
                'severity'      => $r->action_type ?? '—',
                'status'        => $r->status,
                'created_at'    => $r->created_at ? \Carbon\Carbon::parse($r->created_at)->format('Y-m-d') : '—',
            ]);
    }

    private function queryKpiCompletion(): \Illuminate\Support\Collection
    {
        return DB::table('pa_employee_kpis as k')
            ->join('pa_cycles as c', 'c.id', '=', 'k.cycle_id')
            ->leftJoin('pa_kpi_scores as s', 's.employee_kpi_id', '=', 'k.id')
            ->selectRaw("k.employee_id, k.title as kpi_name, k.target_value, COALESCE(s.achieved_value,0) as actual_value, COALESCE(s.score_pct,0) as score, c.name as cycle")
            ->orderBy('c.id', 'desc')
            ->limit(200)
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'kpi_name'      => $r->kpi_name,
                'target_value'  => $r->target_value,
                'actual_value'  => $r->actual_value,
                'score'         => $r->score,
                'cycle'         => $r->cycle,
            ]);
    }

    private function queryFeedbackSummary(): \Illuminate\Support\Collection
    {
        $raw = DB::table('pa_feedback')
            ->selectRaw("employee_id, feedback_type, COUNT(*) as cnt")
            ->groupBy('employee_id', 'feedback_type')
            ->get();

        return $raw->groupBy('employee_id')->map(function ($rows, $empId) {
            $types = $rows->pluck('cnt', 'feedback_type');
            return [
                'employee_name'  => $this->resolveEmployeeName((int) $empId),
                'department'     => $this->resolveDepartment((int) $empId),
                'praise_count'   => (int) ($types['praise']   ?? $types['positive']    ?? 0),
                'coaching_count' => (int) ($types['coaching'] ?? $types['development'] ?? 0),
                'concern_count'  => (int) ($types['concern']  ?? $types['negative']    ?? 0),
                'total_count'    => (int) $rows->sum('cnt'),
            ];
        })->values();
    }

    private function queryPipOutcomes(): \Illuminate\Support\Collection
    {
        return DB::table('pa_pip_plans')
            ->whereIn('status', ['closed_improved', 'closed_not_improved', 'escalated'])
            ->orderByDesc('actual_end_date')
            ->get()
            ->map(fn($r) => [
                'employee_name' => $this->resolveEmployeeName($r->employee_id),
                'department'    => $this->resolveDepartment($r->employee_id),
                'outcome'       => $r->status,
                'start_date'    => $r->start_date,
                'end_date'      => $r->actual_end_date ?? '—',
                'duration_days' => $r->start_date && $r->actual_end_date
                    ? \Carbon\Carbon::parse($r->start_date)->diffInDays(\Carbon\Carbon::parse($r->actual_end_date))
                    : '—',
            ]);
    }

    private function queryProbationReviews(): \Illuminate\Support\Collection
    {
        if (!DB::getSchemaBuilder()->hasTable('pa_probation_reviews')) return collect();

        return DB::table('pa_probation_reviews')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn($r) => [
                'employee_name'      => $this->resolveEmployeeName($r->employee_id),
                'department'         => $this->resolveDepartment($r->employee_id),
                'decision'           => $r->recommendation ?? '—',
                'risk_score'         => $r->risk_score ?? '—',
                'review_date'        => $r->end_date ?? $r->created_at,
                'probation_end_date' => $r->end_date ?? '—',
            ]);
    }

    private function formatScheduledReport(PaScheduledReport $r): array
    {
        return [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'cadence'      => $r->cadence,
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'format'       => $r->format,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'next_run_at'  => $r->next_run_at?->toISOString() ?? null,
            'last_run_at'  => $r->last_run_at?->toISOString() ?? null,
        ];
    }
}
