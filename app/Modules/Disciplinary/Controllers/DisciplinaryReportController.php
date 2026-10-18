<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DisciplinaryAppeal;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigation;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyViolation;
use App\Modules\Disciplinary\Models\DisciplinaryRiskScore;
use App\Modules\Disciplinary\Models\DisciplinaryScheduledReport;
use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use App\Modules\Disciplinary\Models\DisciplinaryWarning;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Modules\Disciplinary\Exports\DisciplinaryReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class DisciplinaryReportController extends Controller
{
    use DisciplinaryAuthTrait;

    // ── Reports index ─────────────────────────────────────────────────────────

    public function index(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $employees  = $this->employeeList();
        $categories = DB::table('disciplinary_categories')->where('active', true)->orderBy('name')->get(['id', 'name', 'severity_level']);

        // ── Open Cases ────────────────────────────────────────────────────────
        $openCases = DisciplinaryCase::with(['employee.person', 'category', 'status'])
            ->where(fn($q) => $q->whereDoesntHave('status')->orWhereHas('status', fn($q2) => $q2->where('is_closed', false)))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($c) => [
                'case_number'    => $c->case_number,
                'employee'       => $c->employeeName(),
                'category'       => $c->category?->name ?? '—',
                'severity'       => $c->category?->severity_level ?? '—',
                'status'         => $c->status?->name ?? 'No Status',
                'incident_date'  => $c->incident_date?->format('d M Y'),
                'hearing_date'   => $c->hearing_date?->format('d M Y'),
                'days_open'      => (int) $c->created_at->diffInDays(now()),
                'created_at'     => $c->created_at->format('d M Y'),
            ]);

        // ── Case Resolution ───────────────────────────────────────────────────
        $caseResolution = DisciplinaryCase::with(['employee.person', 'category', 'status', 'outcome'])
            ->whereHas('status', fn($q) => $q->where('is_closed', true))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($c) => [
                'case_number'    => $c->case_number,
                'employee'       => $c->employeeName(),
                'category'       => $c->category?->name ?? '—',
                'severity'       => $c->category?->severity_level ?? '—',
                'status'         => $c->status?->name ?? '—',
                'outcome'        => $c->outcome?->verdict ?? '—',
                'sanction'       => $c->outcome?->sanction_type ?? '—',
                'incident_date'  => $c->incident_date?->format('d M Y'),
                'closed_at'      => $c->updated_at->format('d M Y'),
                'days_to_close'  => (int) $c->created_at->diffInDays($c->updated_at),
            ]);

        // ── Hearings ─────────────────────────────────────────────────────────
        $hearings = DisciplinaryHearing::with(['case.employee.person', 'case.category'])
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(fn($h) => [
                'hearing_number' => $h->hearing_number,
                'case_number'    => $h->case?->case_number ?? '—',
                'employee'       => $h->case?->employeeName() ?? '—',
                'category'       => $h->case?->category?->name ?? '—',
                'hearing_type'   => $h->hearing_type,
                'status'         => $h->status,
                'scheduled_at'   => $h->scheduled_at?->format('d M Y H:i'),
                'venue'          => $h->venue ?? '—',
                'venue_type'     => $h->venue_type,
                'notice_issued'  => $h->notice_issued ? 'Yes' : 'No',
            ]);

        // ── Investigations ────────────────────────────────────────────────────
        $investigations = DisciplinaryInvestigation::with(['case.employee.person', 'case.category', 'investigator.person'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($i) => [
                'investigation_number' => $i->investigation_number,
                'case_number'          => $i->case?->case_number ?? '—',
                'employee'             => $i->case?->employeeName() ?? '—',
                'category'             => $i->case?->category?->name ?? '—',
                'investigator'         => $i->investigator?->person?->full_name ?? $i->investigator?->email ?? '—',
                'status'               => $i->status,
                'due_date'             => $i->due_date?->format('d M Y'),
                'days_open'            => (int) $i->created_at->diffInDays(now()),
                'progress'             => $i->checklistProgress()['pct'] ?? 0,
                'created_at'           => $i->created_at->format('d M Y'),
            ]);

        // ── Warnings ─────────────────────────────────────────────────────────
        $warnings = DisciplinaryWarning::with(['employee.person', 'case', 'issuedBy.person'])
            ->orderByDesc('issued_date')
            ->get()
            ->map(fn($w) => [
                'employee'      => $w->employee?->person?->full_name ?? $w->employee?->email ?? '—',
                'warning_type'  => $w->warning_type,
                'case_number'   => $w->case?->case_number ?? '—',
                'reason'        => $w->reason,
                'issued_date'   => $w->issued_date?->format('d M Y'),
                'expiry_date'   => $w->expiry_date?->format('d M Y'),
                'issued_by'     => $w->issuedBy?->person?->full_name ?? $w->issuedBy?->email ?? '—',
                'is_expired'    => $w->is_expired ? 'Yes' : 'No',
                'status'        => $w->is_expired ? 'Expired' : 'Active',
            ]);

        // ── Suspensions ───────────────────────────────────────────────────────
        $suspensions = DisciplinarySuspension::with(['employee.person', 'case', 'issuedBy.person'])
            ->orderByDesc('start_date')
            ->get()
            ->map(fn($s) => [
                'employee'         => $s->employee?->person?->full_name ?? $s->employee?->email ?? '—',
                'suspension_type'  => $s->suspension_type,
                'case_number'      => $s->case?->case_number ?? '—',
                'start_date'       => $s->start_date?->format('d M Y'),
                'end_date'         => $s->end_date?->format('d M Y') ?? 'Indefinite',
                'status'           => $s->status,
                'reason'           => $s->reason,
                'issued_by'        => $s->issuedBy?->person?->full_name ?? $s->issuedBy?->email ?? '—',
                'days'             => $s->start_date && $s->end_date
                    ? (int) $s->start_date->diffInDays($s->end_date) : '—',
            ]);

        // ── Corrective Actions ────────────────────────────────────────────────
        $correctiveActions = DisciplinaryCorrectiveAction::with(['employee.person', 'case', 'assignedBy.person'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($a) => [
                'action_number'    => $a->action_number,
                'employee'         => $a->employee?->person?->full_name ?? $a->employee?->email ?? '—',
                'action_type'      => $a->action_type,
                'title'            => $a->title,
                'case_number'      => $a->case?->case_number ?? '—',
                'status'           => $a->status,
                'progress'         => $a->progress_percent . '%',
                'due_date'         => $a->due_date?->format('d M Y'),
                'completed_date'   => $a->completed_date?->format('d M Y') ?? '—',
                'assigned_by'      => $a->assignedBy?->person?->full_name ?? '—',
                'is_mandatory'     => $a->is_mandatory ? 'Yes' : 'No',
            ]);

        // ── Appeals ───────────────────────────────────────────────────────────
        $appeals = DisciplinaryAppeal::with(['employee.person', 'case', 'outcome'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn($a) => [
                'appeal_number' => $a->appeal_number,
                'employee'      => $a->employee?->person?->full_name ?? $a->employee?->email ?? '—',
                'case_number'   => $a->case?->case_number ?? '—',
                'grounds'       => $a->grounds,
                'status'        => $a->status,
                'submitted_at'  => $a->submitted_at?->format('d M Y'),
                'verdict'       => $a->outcome?->verdict ?? '—',
                'decided_at'    => $a->outcome?->decided_at?->format('d M Y') ?? '—',
            ]);

        // ── Incidents ─────────────────────────────────────────────────────────
        $incidents = DisciplinaryIncident::with(['category', 'reporter.person', 'investigator.person'])
            ->orderByDesc('incident_date')
            ->get()
            ->map(fn($i) => [
                'incident_number' => $i->incident_number,
                'title'           => $i->title,
                'category'        => $i->category?->name ?? '—',
                'severity_level'  => $i->severity_level,
                'status'          => $i->status,
                'incident_date'   => $i->incident_date?->format('d M Y H:i'),
                'reporter'        => $i->is_anonymous ? 'Anonymous' : ($i->reporter?->person?->full_name ?? '—'),
                'investigator'    => $i->investigator?->person?->full_name ?? '—',
                'auto_assigned'   => $i->auto_assigned ? 'Yes' : 'No',
                'created_at'      => $i->created_at->format('d M Y'),
            ]);

        // ── Policy Violations ─────────────────────────────────────────────────
        $policyViolations = DisciplinaryPolicyViolation::with(['actor.person', 'case'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($v) => [
                'rule_name'      => $v->rule_name,
                'violation_type' => $v->violation_type,
                'severity'       => $v->severity,
                'employee'       => $v->actor?->person?->full_name ?? $v->actor?->email ?? '—',
                'case_number'    => $v->case?->case_number ?? '—',
                'action_taken'   => $v->action_taken ?? '—',
                'is_resolved'    => $v->is_resolved ? 'Yes' : 'No',
                'resolved_at'    => $v->resolved_at?->format('d M Y'),
                'created_at'     => $v->created_at->format('d M Y'),
            ]);

        // ── Risk Scores ───────────────────────────────────────────────────────
        $riskScores = DisciplinaryRiskScore::with('employee.person')
            ->orderByDesc('risk_score')
            ->get()
            ->map(fn($r) => [
                'employee'           => $r->employee?->person?->full_name ?? $r->employee?->email ?? '—',
                'risk_score'         => round($r->risk_score, 1),
                'risk_level'         => $r->risk_level,
                'total_cases'        => $r->total_cases,
                'is_repeat_offender' => $r->is_repeat_offender ? 'Yes' : 'No',
                'last_case_date'     => $r->last_case_date?->format('d M Y'),
                'updated_at'         => $r->updated_at->format('d M Y'),
            ]);

        return Inertia::render('Modules/Disciplinary/Reports/Index', [
            'employees'         => $employees,
            'categories'        => $categories,
            'openCases'         => $openCases,
            'caseResolution'    => $caseResolution,
            'hearings'          => $hearings,
            'investigations'    => $investigations,
            'warnings'          => $warnings,
            'suspensions'       => $suspensions,
            'correctiveActions' => $correctiveActions,
            'appeals'           => $appeals,
            'incidents'         => $incidents,
            'policyViolations'  => $policyViolations,
            'riskScores'        => $riskScores,
        ]);
    }

    // ── Scheduled reports ─────────────────────────────────────────────────────

    public function scheduledReports(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $reports = DisciplinaryScheduledReport::orderBy('cadence')->orderBy('name')->get()
            ->map(fn($r) => $this->formatScheduledReport($r));

        return Inertia::render('Modules/Disciplinary/Reports/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => $this->availableReportKeys(),
        ]);
    }

    public function storeScheduledReport(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

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

        $report = new DisciplinaryScheduledReport($data);
        $report->created_by  = Auth::id();
        $report->next_run_at = $report->computeNextRun();
        $report->save();

        return back()->with('success', 'Scheduled report created.');
    }

    public function updateScheduledReport(Request $request, DisciplinaryScheduledReport $scheduledReport): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

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

    public function destroyScheduledReport(DisciplinaryScheduledReport $scheduledReport): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $scheduledReport->delete();
        return back()->with('success', 'Scheduled report deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function availableReportKeys(): array
    {
        return [
            'open_cases'         => 'Open Cases',
            'case_resolution'    => 'Case Resolution',
            'hearings'           => 'Hearings',
            'investigations'     => 'Investigations',
            'warnings'           => 'Warnings',
            'suspensions'        => 'Suspensions',
            'corrective_actions' => 'Corrective Actions',
            'appeals'            => 'Appeals',
            'incidents'          => 'Incidents',
            'policy_violations'  => 'Policy Violations',
            'risk_scores'        => 'Risk Scores',
        ];
    }

    private function formatScheduledReport(DisciplinaryScheduledReport $r): array
    {
        return [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'cadence'      => ucfirst($r->cadence),
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'format'       => $r->format,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'next_run'     => $r->next_run_at?->format('D d M Y H:i') ?? '—',
            'last_run_at'  => $r->last_run_at?->format('d M Y H:i'),
        ];
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function exportReport(string $key, string $format): mixed
    {
        abort_unless($this->isDiscAdmin(), 403);
        abort_unless(array_key_exists($key, $this->availableReportKeys()), 404);
        abort_unless(in_array($format, ['pdf', 'excel']), 404);

        [$title, $headers, $rows] = $this->buildReportData($key);
        $filename = str_replace(' ', '-', strtolower($title)) . '-' . now()->format('Y-m-d');

        if ($format === 'excel') {
            return Excel::download(new DisciplinaryReportExport($title, $headers, $rows), $filename . '.xlsx');
        }

        $pdf = Pdf::loadView('reports.disciplinary-report', [
            'title'   => $title,
            'headers' => $headers,
            'rows'    => $rows,
            'date'    => now()->format('d M Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }

    public function buildReportData(string $key): array
    {
        $keys = $this->availableReportKeys();
        $title = $keys[$key];

        return match ($key) {
            'open_cases' => [
                $title,
                ['Case #', 'Employee', 'Category', 'Severity', 'Status', 'Incident Date', 'Hearing Date', 'Days Open'],
                DisciplinaryCase::with(['employee.person', 'category', 'status'])
                    ->where(fn($q) => $q->whereDoesntHave('status')->orWhereHas('status', fn($q2) => $q2->where('is_closed', false)))
                    ->orderByDesc('created_at')->get()
                    ->map(fn($c) => [$c->case_number, $c->employeeName(), $c->category?->name ?? '—', $c->category?->severity_level ?? '—', $c->status?->name ?? 'No Status', $c->incident_date?->format('d M Y'), $c->hearing_date?->format('d M Y'), (int) $c->created_at->diffInDays(now())])
                    ->toArray(),
            ],
            'case_resolution' => [
                $title,
                ['Case #', 'Employee', 'Category', 'Severity', 'Status', 'Outcome', 'Sanction', 'Incident Date', 'Closed', 'Days to Close'],
                DisciplinaryCase::with(['employee.person', 'category', 'status', 'outcome'])
                    ->whereHas('status', fn($q) => $q->where('is_closed', true))
                    ->orderByDesc('updated_at')->get()
                    ->map(fn($c) => [$c->case_number, $c->employeeName(), $c->category?->name ?? '—', $c->category?->severity_level ?? '—', $c->status?->name ?? '—', $c->outcome?->verdict ?? '—', $c->outcome?->sanction_type ?? '—', $c->incident_date?->format('d M Y'), $c->updated_at->format('d M Y'), (int) $c->created_at->diffInDays($c->updated_at)])
                    ->toArray(),
            ],
            'hearings' => [
                $title,
                ['Hearing #', 'Case #', 'Employee', 'Category', 'Type', 'Status', 'Scheduled', 'Venue', 'Mode', 'Notice Issued'],
                DisciplinaryHearing::with(['case.employee.person', 'case.category'])
                    ->orderByDesc('scheduled_at')->get()
                    ->map(fn($h) => [$h->hearing_number, $h->case?->case_number ?? '—', $h->case?->employeeName() ?? '—', $h->case?->category?->name ?? '—', $h->hearing_type, $h->status, $h->scheduled_at?->format('d M Y H:i'), $h->venue ?? '—', $h->venue_type, $h->notice_issued ? 'Yes' : 'No'])
                    ->toArray(),
            ],
            'investigations' => [
                $title,
                ['Investigation #', 'Case #', 'Employee', 'Category', 'Investigator', 'Status', 'Due Date', 'Days Open', 'Progress %'],
                DisciplinaryInvestigation::with(['case.employee.person', 'case.category', 'investigator.person'])
                    ->orderByDesc('created_at')->get()
                    ->map(fn($i) => [$i->investigation_number, $i->case?->case_number ?? '—', $i->case?->employeeName() ?? '—', $i->case?->category?->name ?? '—', $i->investigator?->person?->full_name ?? $i->investigator?->email ?? '—', $i->status, $i->due_date?->format('d M Y'), (int) $i->created_at->diffInDays(now()), $i->checklistProgress()['pct'] ?? 0])
                    ->toArray(),
            ],
            'warnings' => [
                $title,
                ['Employee', 'Warning Type', 'Case #', 'Reason', 'Issued Date', 'Expiry Date', 'Issued By', 'Status'],
                DisciplinaryWarning::with(['employee.person', 'case', 'issuedBy.person'])
                    ->orderByDesc('issued_date')->get()
                    ->map(fn($w) => [$w->employee?->person?->full_name ?? $w->employee?->email ?? '—', $w->warning_type, $w->case?->case_number ?? '—', $w->reason, $w->issued_date?->format('d M Y'), $w->expiry_date?->format('d M Y'), $w->issuedBy?->person?->full_name ?? $w->issuedBy?->email ?? '—', $w->is_expired ? 'Expired' : 'Active'])
                    ->toArray(),
            ],
            'suspensions' => [
                $title,
                ['Employee', 'Type', 'Case #', 'Start Date', 'End Date', 'Status', 'Days', 'Issued By'],
                DisciplinarySuspension::with(['employee.person', 'case', 'issuedBy.person'])
                    ->orderByDesc('start_date')->get()
                    ->map(fn($s) => [$s->employee?->person?->full_name ?? $s->employee?->email ?? '—', $s->suspension_type, $s->case?->case_number ?? '—', $s->start_date?->format('d M Y'), $s->end_date?->format('d M Y') ?? 'Indefinite', $s->status, $s->start_date && $s->end_date ? (int) $s->start_date->diffInDays($s->end_date) : '—', $s->issuedBy?->person?->full_name ?? $s->issuedBy?->email ?? '—'])
                    ->toArray(),
            ],
            'corrective_actions' => [
                $title,
                ['Action #', 'Employee', 'Type', 'Title', 'Case #', 'Status', 'Progress', 'Due Date', 'Completed', 'Assigned By', 'Mandatory'],
                DisciplinaryCorrectiveAction::with(['employee.person', 'case', 'assignedBy.person'])
                    ->orderByDesc('created_at')->get()
                    ->map(fn($a) => [$a->action_number, $a->employee?->person?->full_name ?? $a->employee?->email ?? '—', $a->action_type, $a->title, $a->case?->case_number ?? '—', $a->status, $a->progress_percent . '%', $a->due_date?->format('d M Y'), $a->completed_date?->format('d M Y') ?? '—', $a->assignedBy?->person?->full_name ?? '—', $a->is_mandatory ? 'Yes' : 'No'])
                    ->toArray(),
            ],
            'appeals' => [
                $title,
                ['Appeal #', 'Employee', 'Case #', 'Grounds', 'Status', 'Submitted', 'Verdict', 'Decided'],
                DisciplinaryAppeal::with(['employee.person', 'case', 'outcome'])
                    ->orderByDesc('submitted_at')->get()
                    ->map(fn($a) => [$a->appeal_number, $a->employee?->person?->full_name ?? $a->employee?->email ?? '—', $a->case?->case_number ?? '—', $a->grounds, $a->status, $a->submitted_at?->format('d M Y'), $a->outcome?->verdict ?? '—', $a->outcome?->decided_at?->format('d M Y') ?? '—'])
                    ->toArray(),
            ],
            'incidents' => [
                $title,
                ['Incident #', 'Title', 'Category', 'Severity', 'Status', 'Date', 'Reporter', 'Investigator', 'Auto Assigned'],
                DisciplinaryIncident::with(['category', 'reporter.person', 'investigator.person'])
                    ->orderByDesc('incident_date')->get()
                    ->map(fn($i) => [$i->incident_number, $i->title, $i->category?->name ?? '—', $i->severity_level, $i->status, $i->incident_date?->format('d M Y H:i'), $i->is_anonymous ? 'Anonymous' : ($i->reporter?->person?->full_name ?? '—'), $i->investigator?->person?->full_name ?? '—', $i->auto_assigned ? 'Yes' : 'No'])
                    ->toArray(),
            ],
            'policy_violations' => [
                $title,
                ['Rule', 'Type', 'Severity', 'Employee', 'Case #', 'Action Taken', 'Resolved', 'Resolved Date', 'Created'],
                DisciplinaryPolicyViolation::with(['actor.person', 'case'])
                    ->orderByDesc('created_at')->get()
                    ->map(fn($v) => [$v->rule_name, $v->violation_type, $v->severity, $v->actor?->person?->full_name ?? $v->actor?->email ?? '—', $v->case?->case_number ?? '—', $v->action_taken ?? '—', $v->is_resolved ? 'Yes' : 'No', $v->resolved_at?->format('d M Y'), $v->created_at->format('d M Y')])
                    ->toArray(),
            ],
            'risk_scores' => [
                $title,
                ['Employee', 'Risk Score', 'Risk Level', 'Total Cases', 'Repeat Offender', 'Last Case Date', 'Updated'],
                DisciplinaryRiskScore::with('employee.person')
                    ->orderByDesc('risk_score')->get()
                    ->map(fn($r) => [$r->employee?->person?->full_name ?? $r->employee?->email ?? '—', round($r->risk_score, 1), $r->risk_level, $r->total_cases, $r->is_repeat_offender ? 'Yes' : 'No', $r->last_case_date?->format('d M Y'), $r->updated_at->format('d M Y')])
                    ->toArray(),
            ],
            default => [$title, [], []],
        };
    }

    private function employeeList(): \Illuminate\Support\Collection
    {
        return User::join('hr_people as p', 'p.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email']);
    }
}
