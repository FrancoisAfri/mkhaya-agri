<?php

namespace App\Modules\Policies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Policies\Exports\PolicyReportRowsExport;
use App\Modules\Policies\Models\PolicyScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyInsightsController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('Policies/Dashboard/Index', [
            'exceptionTrends' => $this->exceptionTrends(),
            'complianceDashboard' => $this->complianceDashboard(),
            'behaviorAnalytics' => $this->behaviorAnalytics(),
            'policyEffectiveness' => $this->policyEffectiveness(),
        ]);
    }

    public function reports(): Response
    {
        return Inertia::render('Policies/Reports/Index', [
            'compliance' => $this->complianceDashboard(),
            'attestations' => $this->attestationsReport(),
            'policies' => $this->policiesReport(),
            'questionResults' => $this->questionResultsReport(),
            'policyEvents' => $this->policyEventsReport(),
            'policyCases' => $this->policyCasesReport(),
            'investigations' => $this->investigationsReport(),
            'exceptions' => $this->exceptionsReport(),
            'divisions' => $this->getDivisions(),
            'departments' => $this->getDepartments(),
            'sections' => $this->getSections(),
        ]);
    }

    // ── Scheduled Reports ────────────────────────────────────────────────────────

    private array $reportKeyLabels = [
        'violation'       => 'Violation Reports',
        'compliance'      => 'Compliance Reports',
        'exception'       => 'Exception Reports',
        'attestations'    => 'Attestation Reports',
        'policies'        => 'Policy Inventory Reports',
        'questionResults' => 'Question Results Reports',
        'policyEvents'    => 'Policy Events Reports',
        'investigations'  => 'Investigation Reports',
    ];

    public function scheduledReports(): Response
    {
        $reports = PolicyScheduledReport::orderByDesc('created_at')->get()->map(fn($r) => [
            'id'          => $r->id,
            'name'        => $r->name,
            'report_key'  => $r->report_key,
            'cadence'     => $r->cadence,
            'run_time'    => $r->run_time,
            'day_of_week' => $r->day_of_week,
            'day_of_month'=> $r->day_of_month,
            'format'      => $r->format,
            'recipients'  => $r->recipients,
            'active'      => $r->active,
            'next_run'    => $r->next_run_at?->format('d M Y H:i'),
            'last_run_at' => $r->last_run_at?->format('d M Y H:i'),
        ]);

        return Inertia::render('Policies/Reports/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => $this->reportKeyLabels,
        ]);
    }

    public function storeScheduled(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'report_key'   => 'required|string',
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => 'required|string',
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string',
            'active'       => 'boolean',
        ]);

        $data['created_by'] = Auth::id();
        $scheduled = PolicyScheduledReport::create($data);
        $scheduled->update(['next_run_at' => $scheduled->computeNextRun()]);

        return redirect()->back();
    }

    public function updateScheduled(Request $request, PolicyScheduledReport $scheduledReport): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'report_key'   => 'required|string',
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => 'required|string',
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string',
            'active'       => 'boolean',
        ]);

        $scheduledReport->update($data);
        $scheduledReport->update(['next_run_at' => $scheduledReport->computeNextRun()]);

        return redirect()->back();
    }

    public function destroyScheduled(PolicyScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->delete();
        return redirect()->back();
    }

    public function generate(Request $request, string $reportKey): JsonResponse
    {
        $rows = $this->rowsByKey($reportKey);
        $rows = $this->applyReportFilters($rows, $request);

        return response()->json([
            'report' => $reportKey,
            'count' => count($rows),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function export(Request $request, string $reportKey, string $format)
    {
        $rows = $this->rowsByKey($reportKey);
        $rows = $this->applyReportFilters($rows, $request);

        if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            abort(404, 'Unsupported export format');
        }

        if ($format === 'xlsx') {
            return $this->downloadXlsxExport($reportKey, $rows);
        }

        if ($format === 'csv') {
            return $this->streamCsvExport($reportKey, $rows);
        }

        return $this->downloadPdfExport($reportKey, $rows);
    }

    public function rowsByKey(string $reportKey): array
    {
        return match ($reportKey) {
            'violation', 'policyCases' => $this->policyCasesReport(),
            'compliance' => $this->complianceDashboard(),
            'exception', 'exceptions' => $this->exceptionsReport(),
            'attestations' => $this->attestationsReport(),
            'policies' => $this->policiesReport(),
            'questionResults' => $this->questionResultsReport(),
            'policyEvents' => $this->policyEventsReport(),
            'investigations' => $this->investigationsReport(),
            default => [],
        };
    }

    private function applyReportFilters(array $rows, Request $request): array
    {
        $departmentNames = $this->departmentNamesFromFilters($request);
        $sectionName = $this->sectionNameFromFilters($request);
        $status = strtolower((string) $request->query('status', ''));
        $fromTs = $this->timestampOrNull($request->query('dateFrom'));
        $toTs = $this->timestampOrNull($request->query('dateTo'));

        return array_values(array_filter($rows, function ($row) use ($departmentNames, $sectionName, $status, $fromTs, $toTs) {
            if (!is_array($row)) {
                return false;
            }

            if (!empty($departmentNames) && isset($row['department']) && !in_array((string) $row['department'], $departmentNames, true)) {
                return false;
            }

            if (!empty($sectionName) && isset($row['section']) && (string) $row['section'] !== $sectionName) {
                return false;
            }

            if ($status !== '') {
                $statusCandidates = [
                    strtolower((string) ($row['status'] ?? '')),
                    strtolower((string) ($row['case_status'] ?? '')),
                    strtolower((string) ($row['investigation_status'] ?? '')),
                ];

                if (!in_array($status, $statusCandidates, true)) {
                    return false;
                }
            }

            if ($fromTs !== null || $toTs !== null) {
                $rowTs = $this->extractDateFromRow($row);
                if ($rowTs !== null) {
                    if ($fromTs !== null && $rowTs < $fromTs) {
                        return false;
                    }
                    if ($toTs !== null && $rowTs > $toTs) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    private function departmentNamesFromFilters(Request $request): array
    {
        if (!Schema::hasTable('company_units')) {
            return [];
        }

        $departmentId = $request->query('department');
        if (!empty($departmentId)) {
            $name = DB::table('company_units')->where('id', $departmentId)->value('name');
            return $name ? [$name] : [];
        }

        $divisionId = $request->query('division');
        if (empty($divisionId)) {
            return [];
        }

        return DB::table('company_units')
            ->where('type', 'department')
            ->where('parent_id', $divisionId)
            ->pluck('name')
            ->filter(fn ($name) => !empty($name))
            ->values()
            ->toArray();
    }

    private function sectionNameFromFilters(Request $request): ?string
    {
        if (!Schema::hasTable('company_units')) {
            return null;
        }

        $sectionId = $request->query('section');
        if (empty($sectionId)) {
            return null;
        }

        $section = DB::table('company_units')->where('id', $sectionId)->select('name', 'parent_id')->first();
        if (!$section) {
            return null;
        }

        return (string) $section->name;
    }

    private function extractDateFromRow(array $row): ?int
    {
        $dateKeys = [
            'created_at',
            'timestamp',
            'attempted_at',
            'deadline',
            'acknowledged_at',
            'start_date',
            'end_date',
            'created_date',
            'review_date',
            'approved_at',
            'resolved_at',
        ];

        foreach ($dateKeys as $key) {
            if (!isset($row[$key])) {
                continue;
            }

            $ts = $this->timestampOrNull($row[$key]);
            if ($ts !== null) {
                return $ts;
            }
        }

        return null;
    }

    private function timestampOrNull($value): ?int
    {
        if (empty($value) && $value !== '0') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $ts = strtotime((string) $value);
        return $ts === false ? null : $ts;
    }

    private function streamCsvExport(string $reportKey, array $rows): StreamedResponse
    {
        $filename = sprintf('%s_%s.csv', $reportKey, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            if (count($rows) > 0) {
                fputcsv($output, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($output, array_values($row));
                }
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function downloadXlsxExport(string $reportKey, array $rows)
    {
        $filename = sprintf('%s_%s.xlsx', $reportKey, now()->format('Y-m-d'));

        return Excel::download(new PolicyReportRowsExport($rows), $filename);
    }

    private function downloadPdfExport(string $reportKey, array $rows)
    {
        $filename = sprintf('%s_%s.pdf', $reportKey, now()->format('Y-m-d'));
        $headers = count($rows) > 0 ? array_keys($rows[0]) : [];

        $html = '<html><head><style>'
            . 'body{font-family:Arial,sans-serif;font-size:11px;}'
            . 'h2{margin:0 0 8px 0;}'
            . 'p{margin:0 0 12px 0;color:#666;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #ddd;padding:6px;text-align:left;}'
            . 'th{background:#f3f4f6;}'
            . '</style></head><body>'
            . '<h2>' . e($reportKey) . ' report</h2>'
            . '<p>Generated at ' . e(now()->format('Y-m-d H:i:s')) . '</p>'
            . '<table><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>' . e((string) $header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $html .= '<td>' . e((string) ($row[$header] ?? '-')) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    private function getDivisions(): array
    {
        if (!Schema::hasTable('company_units')) {
            return [];
        }

        return DB::table('company_units')
            ->where('type', 'division')
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn ($name, $id) => ['id' => $id, 'name' => $name])
            ->values()
            ->toArray();
    }

    private function getDepartments(): array
    {
        if (!Schema::hasTable('company_units')) {
            return [];
        }

        return DB::table('company_units')
            ->where('type', 'department')
            ->where('active', true)
            ->orderBy('name')
            ->select('id', 'name', 'parent_id')
            ->get()
            ->map(fn ($dept) => ['id' => $dept->id, 'name' => $dept->name, 'parent_id' => $dept->parent_id])
            ->toArray();
    }

    private function getSections(): array
    {
        if (!Schema::hasTable('company_units')) {
            return [];
        }

        return DB::table('company_units')
            ->where('type', 'section')
            ->where('active', true)
            ->orderBy('name')
            ->select('id', 'name', 'parent_id')
            ->get()
            ->map(fn ($section) => ['id' => $section->id, 'name' => $section->name, 'parent_id' => $section->parent_id])
            ->toArray();
    }

    private function userDepartmentSubquery()
    {
        return DB::table('company_unit_user_pivots as cup')
            ->join('company_units as dep', 'dep.id', '=', 'cup.company_id')
            ->where('dep.type', 'department')
            ->groupBy('cup.user_id')
            ->selectRaw('cup.user_id, min(cup.company_id) as department_id');
    }

    private function formatDateValue($value, bool $withTime = true): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $carbon = Carbon::createFromTimestamp((int) $value);
            } else {
                $carbon = Carbon::parse((string) $value);
            }

            return $withTime ? $carbon->format('Y-m-d H:i:s') : $carbon->format('Y-m-d');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function attestationsReport(): array
    {
        if (!Schema::hasTable('policy_attestations')) {
            return [];
        }

        return DB::table('policy_attestations as pa')
            ->join('users as u', 'u.id', '=', 'pa.user_id')
            ->join('policy as p', 'p.id', '=', 'pa.policy_id')
            ->leftJoinSub($this->userDepartmentSubquery(), 'ud', fn ($join) => $join->on('ud.user_id', '=', 'u.id'))
            ->leftJoin('company_units as cu', 'cu.id', '=', 'ud.department_id')
            ->select(
                'pa.uuid',
                'p.name as policy_name',
                'u.email as user_email',
                'cu.name as department',
                'pa.status',
                'pa.acknowledged_at',
                'pa.deadline',
                DB::raw('CASE WHEN pa.deadline < now() AND pa.acknowledged_at IS NULL THEN true ELSE false END as is_overdue')
            )
            ->orderByDesc('pa.created_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->uuid,
                'policy' => $row->policy_name,
                'user' => $row->user_email,
                'department' => $row->department ?? 'Unassigned',
                'status' => $row->status,
                'acknowledged_at' => $this->formatDateValue($row->acknowledged_at),
                'deadline' => $this->formatDateValue($row->deadline),
                'overdue' => $row->is_overdue,
            ])
            ->toArray();
    }

    private function policiesReport(): array
    {
        if (!Schema::hasTable('policy')) {
            return [];
        }

        return DB::table('policy as p')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'p.department_id')
            ->select(
                'p.uuid',
                'p.name',
                'p.status',
                'cu.name as department',
                'p.requires_attestation',
                'p.date as created_date',
                'p.review_date'
            )
            ->orderByDesc('p.date')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->uuid,
                'name' => $row->name,
                'status' => $row->status,
                'department' => $row->department ?? 'Global',
                'requires_attestation' => $row->requires_attestation ? 'Yes' : 'No',
                'created_date' => $this->formatDateValue($row->created_date, false),
                'review_date' => $this->formatDateValue($row->review_date, false),
            ])
            ->toArray();
    }

    private function questionResultsReport(): array
    {
        if (!Schema::hasTable('policy_quiz_attempts')) {
            return [];
        }

        return DB::table('policy_quiz_attempts as pqa')
            ->join('users as u', 'u.id', '=', 'pqa.user_id')
            ->join('policy as p', 'p.id', '=', 'pqa.policy_id')
            ->leftJoinSub($this->userDepartmentSubquery(), 'ud', fn ($join) => $join->on('ud.user_id', '=', 'u.id'))
            ->leftJoin('company_units as cu', 'cu.id', '=', 'ud.department_id')
            ->select(
                'pqa.id',
                'p.name as policy_name',
                'u.email as user_email',
                'cu.name as department',
                'pqa.score',
                'pqa.correct_answers_count',
                'pqa.total_questions',
                'pqa.passed',
                'pqa.completed_at'
            )
            ->whereNotNull('pqa.completed_at')
            ->orderByDesc('pqa.completed_at')
            ->limit(500)
            ->get()
            ->map(function ($row) {
                $total = max(0, (int) $row->total_questions);
                $rawScore = (float) $row->score;
                $correct = $row->correct_answers_count !== null ? (int) $row->correct_answers_count : null;

                if ($correct !== null && $total > 0) {
                    $scoreText = $correct . '/' . $total;
                    $percentage = round(($correct / $total) * 100, 1);
                } elseif ($total > 0 && $rawScore <= $total) {
                    $scoreText = (int) $rawScore . '/' . $total;
                    $percentage = round(($rawScore / $total) * 100, 1);
                } elseif ($total > 0 && $rawScore > $total && $rawScore <= 100) {
                    $derivedCorrect = (int) round(($rawScore / 100) * $total);
                    $scoreText = $derivedCorrect . '/' . $total;
                    $percentage = round($rawScore, 1);
                } else {
                    $scoreText = $total > 0 ? ((int) $rawScore . '/' . $total) : (string) (int) $rawScore;
                    $percentage = round(min(100, $rawScore), 1);
                }

                return [
                    'id' => $row->id,
                    'policy' => $row->policy_name,
                    'user' => $row->user_email,
                    'department' => $row->department ?? 'Unassigned',
                    'score' => $scoreText,
                    'percentage' => $percentage . '%',
                    'status' => $row->passed ? 'Passed' : 'Failed',
                    'attempted_at' => $this->formatDateValue($row->completed_at),
                ];
            })
            ->toArray();
    }

    private function policyEventsReport(): array
    {
        if (!Schema::hasTable('policy_events')) {
            return [];
        }

        return DB::table('policy_events as pe')
            ->join('users as u', 'u.id', '=', 'pe.actor_id')
            ->leftJoinSub($this->userDepartmentSubquery(), 'ud', fn ($join) => $join->on('ud.user_id', '=', 'u.id'))
            ->leftJoin('company_units as cu', 'cu.id', '=', 'ud.department_id')
            ->select(
                'pe.id',
                'pe.module',
                'pe.action',
                'u.email as actor_email',
                'cu.name as department',
                'pe.subject_type',
                'pe.subject_id',
                'pe.created_at'
            )
            ->orderByDesc('pe.created_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'module' => $row->module,
                'action' => $row->action,
                'actor' => $row->actor_email,
                'department' => $row->department ?? 'Unassigned',
                'subject' => $row->subject_type . ' #' . $row->subject_id,
                'timestamp' => $this->formatDateValue($row->created_at),
            ])
            ->toArray();
    }

    private function investigationsReport(): array
    {
        if (!Schema::hasTable('policy_violation_investigations')) {
            return [];
        }

        return DB::table('policy_violation_investigations as vi')
            ->join('policy_violations as pv', 'pv.id', '=', 'vi.violation_id')
            ->join('policy as p', 'p.id', '=', 'pv.policy_id')
            ->join('users as u', 'u.id', '=', 'pv.user_id')
            ->leftJoinSub($this->userDepartmentSubquery(), 'ud', fn ($join) => $join->on('ud.user_id', '=', 'u.id'))
            ->leftJoin('company_units as cu', 'cu.id', '=', 'ud.department_id')
            ->select(
                'vi.id',
                'p.name as policy_name',
                'u.email as user_email',
                'cu.name as department',
                'pv.severity',
                'vi.status',
                'vi.resolution_type',
                'vi.findings',
                'vi.created_at',
                'vi.resolved_at'
            )
            ->orderByDesc('vi.created_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'policy' => $row->policy_name,
                'user' => $row->user_email,
                'department' => $row->department ?? 'Unassigned',
                'severity' => $row->severity,
                'status' => $row->status,
                'resolution' => $row->resolution_type ?? 'Pending',
                'created_at' => $this->formatDateValue($row->created_at),
                'resolved_at' => $this->formatDateValue($row->resolved_at),
            ])
            ->toArray();
    }

    private function policyCasesReport(): array
    {
        if (!Schema::hasTable('policy_violations')) {
            return [];
        }

        $hasInvestigations = Schema::hasTable('policy_violation_investigations');

        $query = DB::table('policy_violations as pv')
            ->leftJoin('policy as p', 'p.id', '=', 'pv.policy_id')
            ->leftJoin('users as u', 'u.id', '=', 'pv.user_id')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'pv.department_id')
            ->select(
                'pv.id',
                'p.name as policy_name',
                'u.email as user_email',
                'cu.name as department',
                'pv.severity',
                'pv.status as case_status',
                'pv.outcome',
                'pv.created_at',
                'pv.resolved_at'
            );

        if ($hasInvestigations) {
            $query->leftJoin('policy_violation_investigations as vi', 'vi.violation_id', '=', 'pv.id')
                ->addSelect(
                    'vi.status as investigation_status',
                    'vi.resolution_type',
                    'vi.findings'
                );
        }

        return $query
            ->orderByDesc('pv.created_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'policy' => $row->policy_name ?? 'Unknown Policy',
                'user' => $row->user_email ?? 'Unknown User',
                'department' => $row->department ?? 'Unassigned',
                'severity' => $row->severity,
                'case_status' => $row->case_status,
                'investigation_status' => $row->investigation_status ?? 'Not started',
                'investigation_result' => $row->resolution_type ?? $row->outcome ?? 'Pending',
                'created_at' => $this->formatDateValue($row->created_at),
                'resolved_at' => $this->formatDateValue($row->resolved_at),
            ])
            ->toArray();
    }

    private function exceptionsReport(): array
    {
        if (!Schema::hasTable('policy_exceptions')) {
            return [];
        }

        $driver = DB::getDriverName();
        $durationExpr = $driver === 'pgsql'
            ? 'CASE WHEN pe.valid_from IS NOT NULL AND pe.valid_until IS NOT NULL THEN (pe.valid_until - pe.valid_from) ELSE 0 END'
            : 'CASE WHEN pe.valid_from IS NOT NULL AND pe.valid_until IS NOT NULL THEN timestampdiff(day, pe.valid_from, pe.valid_until) ELSE 0 END';

        return DB::table('policy_exceptions as pe')
            ->join('users as u', 'u.id', '=', 'pe.requested_by')
            ->join('policy as p', 'p.id', '=', 'pe.policy_id')
            ->leftJoinSub($this->userDepartmentSubquery(), 'ud', fn ($join) => $join->on('ud.user_id', '=', 'u.id'))
            ->leftJoin('company_units as cu', 'cu.id', '=', 'ud.department_id')
            ->select(
                'pe.id',
                'p.name as policy_name',
                'u.email as user_email',
                'cu.name as department',
                'pe.reason',
                'pe.status',
                'pe.valid_from',
                'pe.valid_until',
                'pe.decided_at',
                DB::raw("{$durationExpr} as duration_days")
            )
            ->orderByDesc('pe.created_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'policy' => $row->policy_name,
                'user' => $row->user_email,
                'department' => $row->department ?? 'Unassigned',
                'reason' => $row->reason,
                'status' => $row->status,
                'duration' => ((int) ($row->duration_days ?? 0)) . ' days',
                'start_date' => $this->formatDateValue($row->valid_from, false),
                'end_date' => $this->formatDateValue($row->valid_until, false),
                'approved_at' => $this->formatDateValue($row->decided_at),
            ])
            ->toArray();
    }

    private function exceptionTrends(): array
    {
        if (!Schema::hasTable('policy_exceptions')) {
            return [];
        }

        $from = Carbon::now()->subMonths(5)->startOfMonth();

        $driver = DB::getDriverName();
        $monthExpr = $driver === 'pgsql'
            ? "to_char(created_at, 'YYYY-MM')"
            : "date_format(created_at, '%Y-%m')";

        $monthly = DB::table('policy_exceptions')
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'approved' then 1 else 0 end) as approved")
            ->where('created_at', '>=', $from)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $monthly->map(function ($row) {
            $total = (int) $row->total;
            $approved = (int) $row->approved;

            return [
                'month' => $row->month,
                'total' => $total,
                'approved' => $approved,
                'approval_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
            ];
        })->toArray();
    }

    private function complianceDashboard(): array
    {
        if (!Schema::hasTable('company_units') || !Schema::hasTable('company_unit_user_pivots')) {
            return [];
        }

        $departmentRows = DB::table('company_units as cu')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.company_id', '=', 'cu.id')
            ->where('cu.type', 'department')
            ->select('cu.id', 'cu.name')
            ->selectRaw('count(distinct cup.user_id) as users_count')
            ->groupBy('cu.id', 'cu.name')
            ->get();

        $violationsByDept = Schema::hasTable('policy_violations')
            ? DB::table('policy_violations')
                ->select('department_id')
                ->selectRaw('count(*) as violations')
                ->whereNotNull('department_id')
                ->groupBy('department_id')
                ->pluck('violations', 'department_id')
            : collect();

        $adoptionByDept = collect();
        $attestationByDept = collect();

        if (Schema::hasTable('policy_users')) {
            $adoptionByDept = DB::table('company_units as cu')
                ->leftJoin('company_unit_user_pivots as cup', 'cup.company_id', '=', 'cu.id')
                ->leftJoin('policy_users as pu', 'pu.user_id', '=', 'cup.user_id')
                ->where('cu.type', 'department')
                ->select('cu.id as department_id')
                ->selectRaw('count(distinct cup.user_id) as users_count')
                ->selectRaw('count(distinct case when pu.policy_id is not null then cup.user_id end) as adopted_users')
                ->groupBy('cu.id')
                ->get()
                ->keyBy('department_id');

            if (Schema::hasTable('policy_attestations')) {
                $attestationByDept = DB::table('policy_attestations as pa')
                    ->joinSub($this->userDepartmentSubquery(), 'ud', fn ($join) => $join->on('ud.user_id', '=', 'pa.user_id'))
                    ->select('ud.department_id')
                    ->selectRaw('count(*) as assigned')
                    ->selectRaw('sum(case when pa.acknowledged_at is not null then 1 else 0 end) as completed')
                    ->groupBy('ud.department_id')
                    ->get()
                    ->keyBy('department_id');
            }
        }

        return $departmentRows->map(function ($dept) use ($violationsByDept, $adoptionByDept, $attestationByDept) {
            $usersCount = (int) $dept->users_count;
            $violations = (int) ($violationsByDept[$dept->id] ?? 0);

            $adoptionRow = $adoptionByDept->get($dept->id);
            $adoptedUsers = (int) ($adoptionRow->adopted_users ?? 0);
            $adoptionRate = $usersCount > 0 ? round(($adoptedUsers / $usersCount) * 100, 1) : 0;

            $attRow = $attestationByDept->get($dept->id);
            $assigned = (int) ($attRow->assigned ?? 0);
            $completed = (int) ($attRow->completed ?? 0);
            $attestationCompletion = $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 0;

            $violationPenalty = $usersCount > 0 ? min(100, ($violations / $usersCount) * 25) : 0;
            $complianceScore = max(0, min(100, round((100 - $violationPenalty) * 0.4 + $adoptionRate * 0.3 + $attestationCompletion * 0.3, 1)));

            return [
                'department' => $dept->name,
                'compliance_score' => $complianceScore,
                'policy_adoption_rate' => $adoptionRate,
                'attestation_completion' => $attestationCompletion,
            ];
        })->filter(fn ($row) => $row['department'] !== null)->values()->toArray();
    }

    private function behaviorAnalytics(): array
    {
        $repeatOffenders = [];
        if (Schema::hasTable('policy_repeat_violations')) {
            $repeatOffenders = DB::table('policy_repeat_violations as prv')
                ->join('users as u', 'u.id', '=', 'prv.user_id')
                ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
                ->selectRaw("coalesce(nullif(trim(concat_ws(' ', hp.first_name, hp.surname)), ''), u.email, concat('User #', u.id)) as user_name")
                ->selectRaw('count(*) as repeat_count')
                ->groupByRaw("coalesce(nullif(trim(concat_ws(' ', hp.first_name, hp.surname)), ''), u.email, concat('User #', u.id))")
                ->orderByDesc('repeat_count')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->user_name,
                    'value' => (int) $row->repeat_count,
                ])
                ->toArray();
        }

        $managerOverrides = [];
        if (Schema::hasTable('policy_approval_overrides')) {
            $managerOverrides = DB::table('policy_approval_overrides as pao')
                ->leftJoin('users as u', 'u.id', '=', 'pao.approved_by')
                ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
                ->selectRaw("coalesce(nullif(trim(concat_ws(' ', hp.first_name, hp.surname)), ''), u.email, concat('User #', u.id), 'System') as manager_name")
                ->selectRaw('count(*) as overrides_count')
                ->groupByRaw("coalesce(nullif(trim(concat_ws(' ', hp.first_name, hp.surname)), ''), u.email, concat('User #', u.id), 'System')")
                ->orderByDesc('overrides_count')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->manager_name,
                    'rate' => (int) $row->overrides_count,
                    'label' => ((int) $row->overrides_count) . ' overrides',
                ])
                ->toArray();
        } elseif (Schema::hasTable('policy_exceptions')) {
            $managerOverrides = DB::table('policy_exceptions as pe')
                ->leftJoin('users as u', 'u.id', '=', 'pe.approved_by')
                ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'u.id')
                ->selectRaw("coalesce(nullif(trim(concat_ws(' ', hp.first_name, hp.surname)), ''), u.email, 'System') as manager_name")
                ->selectRaw('count(*) as decisions_count')
                ->groupByRaw("coalesce(nullif(trim(concat_ws(' ', hp.first_name, hp.surname)), ''), u.email, 'System')")
                ->orderByDesc('decisions_count')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->manager_name,
                    'rate' => (int) $row->decisions_count,
                    'label' => ((int) $row->decisions_count) . ' decisions',
                ])
                ->toArray();
        }

        $highRiskPatterns = [];
        if (Schema::hasTable('behavior_patterns')) {
            $highRiskPatterns = DB::table('behavior_patterns')
                ->select('pattern_type')
                ->selectRaw('sum(occurrence_count) as occurrences')
                ->groupBy('pattern_type')
                ->orderByDesc('occurrences')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'pattern' => str_replace('_', ' ', (string) $row->pattern_type),
                    'count' => (int) $row->occurrences,
                ])
                ->toArray();
        } elseif (Schema::hasTable('policy_risk_profiles')) {
            $highRiskPatterns = DB::table('policy_risk_profiles')
                ->select('risk_category')
                ->selectRaw('count(*) as total')
                ->whereIn('risk_category', ['high', 'critical'])
                ->groupBy('risk_category')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [
                    'pattern' => (string) $row->risk_category . ' risk profile',
                    'count' => (int) $row->total,
                ])
                ->toArray();
        }

        return [
            'repeat_offenders' => $repeatOffenders,
            'manager_override_rates' => $managerOverrides,
            'high_risk_patterns' => $highRiskPatterns,
        ];
    }

    private function policyEffectiveness(): array
    {
        $friction = [];
        $ignored = [];
        $aiQuestions = [];

        if (Schema::hasTable('policy')) {
            $hasExceptions = Schema::hasTable('policy_exceptions');
            $hasViolations = Schema::hasTable('policy_violations');

            if ($hasExceptions || $hasViolations) {
                $frictionQuery = DB::table('policy as p')
                    ->select('p.id', 'p.name')
                    ->groupBy('p.id', 'p.name');

                if ($hasExceptions) {
                    $frictionQuery->leftJoin('policy_exceptions as pe', 'pe.policy_id', '=', 'p.id');
                    $frictionQuery->selectRaw('count(distinct pe.id) as exceptions_count');
                } else {
                    $frictionQuery->selectRaw('0 as exceptions_count');
                }

                if ($hasViolations) {
                    $frictionQuery->leftJoin('policy_violations as pv', 'pv.policy_id', '=', 'p.id');
                    $frictionQuery->selectRaw('count(distinct pv.id) as violations_count');
                } else {
                    $frictionQuery->selectRaw('0 as violations_count');
                }

                $friction = $frictionQuery
                    ->orderByDesc('exceptions_count')
                    ->orderByDesc('violations_count')
                    ->limit(5)
                    ->get()
                    ->map(fn ($row) => [
                        'policy' => $row->name,
                        'friction_score' => (int) $row->exceptions_count + (int) $row->violations_count,
                        'details' => (int) $row->exceptions_count . ' exceptions, ' . (int) $row->violations_count . ' violations',
                    ])
                    ->toArray();
            }

            if (Schema::hasTable('policy_users')) {
                $ignored = DB::table('policy as p')
                    ->leftJoin('policy_users as pu', 'pu.policy_id', '=', 'p.id')
                    ->select('p.id', 'p.name')
                    ->selectRaw('count(pu.id) as assigned_count')
                    ->selectRaw('sum(case when pu.read_understood = 1 then 1 else 0 end) as understood_count')
                    ->where('p.status', 'active')
                    ->groupBy('p.id', 'p.name')
                    ->get()
                    ->map(function ($row) {
                        $assigned = (int) $row->assigned_count;
                        $understood = (int) $row->understood_count;
                        $adoptionRate = $assigned > 0 ? round(($understood / $assigned) * 100, 1) : 0;

                        return [
                            'policy' => $row->name,
                            'adoption_rate' => $adoptionRate,
                            'details' => $understood . '/' . $assigned . ' read and understood',
                        ];
                    })
                    ->sortBy('adoption_rate')
                    ->take(5)
                    ->values()
                    ->toArray();
            }
        }

        if (Schema::hasTable('copilot_sessions') && Schema::hasTable('policy')) {
            $policyNameMap = DB::table('policy')->pluck('name', 'id');
            $sessionRows = DB::table('copilot_sessions')
                ->select('referenced_policies')
                ->whereNotNull('referenced_policies')
                ->latest()
                ->limit(1000)
                ->get();

            $counter = [];
            foreach ($sessionRows as $row) {
                $policies = json_decode((string) $row->referenced_policies, true);
                if (!is_array($policies)) {
                    continue;
                }

                foreach ($policies as $policyId) {
                    $pid = (int) $policyId;
                    if ($pid <= 0) {
                        continue;
                    }
                    $counter[$pid] = ($counter[$pid] ?? 0) + 1;
                }
            }

            arsort($counter);
            $aiQuestions = collect(array_slice($counter, 0, 5, true))
                ->map(function ($count, $policyId) use ($policyNameMap) {
                    return [
                        'policy' => $policyNameMap[$policyId] ?? ('Policy #' . $policyId),
                        'questions' => (int) $count,
                    ];
                })
                ->values()
                ->toArray();
        }

        return [
            'policies_causing_friction' => $friction,
            'policies_ignored' => $ignored,
            'policies_frequently_questioned_ai' => $aiQuestions,
        ];
    }
}
