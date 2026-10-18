<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\MonthlyCashflow;
use App\Modules\Operations\ProjectManagement\Models\MonthlyReport;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ReportingPeriod;
use App\Modules\Operations\ProjectManagement\Services\ProjectLedgerPosition;
use App\Modules\Operations\ProjectManagement\Services\MonthlyReportingService;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use App\Modules\Operations\ProjectManagement\Support\Reporting\ReportingStages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The monthly Project & Financial Report.
 *
 * ── Two audiences, one report ───────────────────────────────────────────────
 *
 * A project manager sees the rows for their own projects; a director sees the consolidated report.
 * That is the same filter rather than two screens — `Project::visibleTo` already answers "which
 * projects is this person entitled to", and cost visibility decides whether the three fee columns
 * reach the payload at all.
 *
 * Payloads are built field by field. The report carries fee figures, so a spread would hand a
 * project manager without cost visibility the whole practice's financial position.
 */
class MonthlyReportingController extends Controller
{
    /*
     * A note on the policy used below.
     *
     * The report is a document about the whole portfolio, not about one project, so the per-project
     * abilities do not apply — ProjectPolicy@update takes a Project and passing the class name to
     * it raises rather than denying. `create` is the class-level ability that means "runs projects
     * here", and whoever may start a project may maintain the report that lists them. Issuing is
     * `manageSettings`, because it is the act that freezes a management document.
     */

    public function __construct(private readonly MonthlyReportingService $reporting)
    {
    }

    /** Every month's report, newest first. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $periods = ReportingPeriod::query()
            ->withCount('reports')
            ->orderByDesc('reporting_year')
            ->orderByDesc('reporting_month')
            ->get();

        $now = now();

        return Inertia::render('Modules/ProjectManagement/Reporting/Index', [
            'periods' => $periods->map(fn (ReportingPeriod $p) => [
                'id'           => $p->uuid,
                'name'         => $p->report_name,
                'label'        => $p->label(),
                'year'         => $p->reporting_year,
                'month'        => $p->reporting_month,
                'meeting_date' => optional($p->meeting_date)->toDateString(),
                'status'       => $p->status,
                'projects'     => $p->reports_count,
            ])->all(),
            /*
             * Defaults for the "new report" dialog.
             *
             * The first month that has no report, not simply this one. Once September has been
             * opened the dialog offering September again is offering the one thing that cannot be
             * done — the save comes back "September 2026 already has a report", which reads as the
             * dialog being broken rather than as the default being wrong.
             *
             * There is no `copy_from` here any more. It used to be the month before *today*, pinned
             * when the page loaded, while the month/year selects above it stayed editable: choose
             * October and the rows still came forward from August. Which month "previous" means
             * depends on the month being opened, so the server works it out at save time from the
             * month it was actually given.
             */
            'suggested' => $this->reporting->nextOpenMonth($now),
            'can' => ['create' => $request->user()->can('create', Project::class)],
        ]);
    }

    /** One month's report — the grid the client reads across. */
    public function show(Request $request, string $uuid): Response
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('viewAny', Project::class);

        $canSeeCosts = $request->user()->can('viewCosts', Project::class);

        $rows = $period->reports()
            ->with(['project:id,uuid,code,name', 'cashflows'])
            // A project manager sees their own projects; a director sees everything.
            ->whereIn('project_id', Project::visibleTo($request->user())->select('id'))
            ->get();

        return Inertia::render('Modules/ProjectManagement/Reporting/Show', [
            'period' => [
                'id'           => $period->uuid,
                'name'         => $period->report_name,
                'label'        => $period->label(),
                'meeting_date' => optional($period->meeting_date)->toDateString(),
                'general_comment' => $period->general_comment,
                'status'       => $period->status,
                'issued_at'    => optional($period->issued_at)->toDateTimeString(),
            ],
            // The dynamic columns. Never a fixed set — see ReportingPeriod::months().
            'months' => $period->months(),
            'rows'   => $rows->values()->map(fn (MonthlyReport $r, int $i) => $this->row($r, $i, $canSeeCosts))->all(),
            'stages'   => ReportingStages::stages(),
            'statuses' => ReportingStages::statuses(),
            'people'   => $this->people(),
            'projects' => Project::visibleTo($request->user())
                ->whereNotIn('id', $period->reports()->select('project_id'))
                ->orderBy('name')
                ->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'code' => $p->code, 'name' => $p->name])
                ->all(),
            'summary' => $canSeeCosts ? $this->reporting->summary($period) : null,
            'can' => [
                'edit'  => ! $period->isIssued() && $request->user()->can('create', Project::class),
                'issue' => ! $period->isIssued() && $request->user()->can('manageSettings', Project::class),
                'costs' => $canSeeCosts,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'year'         => ['required', 'integer', 'between:2000,2100'],
            'month'        => ['required', 'integer', 'between:1,12'],
            'meeting_date' => ['nullable', 'date'],
            'cashflow_months' => ['nullable', 'integer', 'between:1,24'],
            // "Copy previous month" — an intention, not a month.
            'copy_previous' => ['nullable', 'boolean'],
        ]);

        /*
         * Which month "previous" means is decided here, from the month being opened.
         *
         * The dialog used to send the uuid of the month before *today*, resolved when the page
         * loaded and never revisited while the user changed the month and year selects sitting
         * directly above the checkbox. Open October and last month's figures came forward from
         * August: not an error, not empty, just silently the wrong month — which is what "copy
         * previous month does not work" turned out to mean.
         */
        $copyFrom = ($data['copy_previous'] ?? false)
            ? $this->reporting->previousPeriod((int) $data['year'], (int) $data['month'])
            : null;

        try {
            $period = $this->reporting->open(
                (int) $data['year'],
                (int) $data['month'],
                array_filter([
                    'meeting_date'    => $data['meeting_date'] ?? null,
                    'cashflow_months' => $data['cashflow_months'] ?? null,
                ]),
                $copyFrom,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['month' => $e->getMessage()]);
        }

        return redirect()
            ->route('project-management.reporting.show', $period->uuid)
            ->with('success', __('project_management.flash.report_opened'));
    }

/** The report's own fields: the meeting date and the general comment. */
    public function updatePeriod(Request $request, string $uuid): RedirectResponse
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', Project::class);

        abort_if($period->isIssued(), 422, 'That report has been issued and can no longer be changed.');

        $data = $request->validate([
            'meeting_date'    => ['nullable', 'date'],
            'general_comment' => ['nullable', 'string', 'max:8000'],
        ]);

        $period->update(array_intersect_key($data, $request->all()));

        return back()->with('success', __('project_management.flash.report_updated'));
    }

    /** Edit one row — the monthly job. */
    public function updateRow(Request $request, string $uuid, string $rowUuid): RedirectResponse
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', Project::class);

        abort_if($period->isIssued(), 422, 'That report has been issued and can no longer be changed.');

        $row = MonthlyReport::where('reporting_period_id', $period->id)->where('uuid', $rowUuid)->firstOrFail();

        $data = $request->validate([
            'project_manager_ids'   => ['nullable', 'array'],
            'project_manager_ids.*' => ['integer'],
            'project_manager_label' => ['nullable', 'string', 'max:190'],
            'stage'                 => ['nullable', 'string', 'max:120'],
            'progress_percentage'   => ['nullable', 'numeric', 'between:0,100'],
            'project_status'        => ['nullable', 'string', 'max:120'],
            // A running commentary beside the status, as many bullets as the month needs.
            'project_status_notes'  => ['nullable', 'string', 'max:8000'],
            // Line breaks and bullet characters are preserved exactly as typed.
            'challenges'            => ['nullable', 'string', 'max:8000'],
            'estimated_professional_fee'        => ['nullable', 'numeric'],
            'professional_fee_invoiced_to_date' => ['nullable', 'numeric'],
            'professional_fee_outstanding'      => ['nullable', 'numeric'],
        ]);

        $fees = ['estimated_professional_fee', 'professional_fee_invoiced_to_date', 'professional_fee_outstanding'];

        if (! $request->user()->can('viewCosts', Project::class)) {
            // Somebody without cost visibility never receives these columns, so they cannot send
            // them either — otherwise the read restriction is decoration.
            $data = array_diff_key($data, array_flip($fees));
        }

        $row->update(array_intersect_key($data, $request->all()) + ['updated_by' => $request->user()->id]);

        return back()->with('success', __('project_management.flash.report_updated'));
    }

    /** One month's projected amount on one row. */
    public function updateCashflow(Request $request, string $uuid, string $rowUuid): RedirectResponse
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', Project::class);
        abort_unless($request->user()->can('viewCosts', Project::class), 403);

        $row = MonthlyReport::where('reporting_period_id', $period->id)->where('uuid', $rowUuid)->firstOrFail();

        $data = $request->validate([
            'year'   => ['required', 'integer', 'between:2000,2100'],
            'month'  => ['required', 'integer', 'between:1,12'],
            'amount' => ['nullable', 'numeric'],
        ]);

        try {
            $this->reporting->setCashflow($row, (int) $data['year'], (int) $data['month'], $data['amount'] ?? 0);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.report_updated'));
    }

    public function addProject(Request $request, string $uuid): RedirectResponse
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', Project::class);

        $data = $request->validate(['project' => ['required', 'uuid']]);
        $project = Project::where('uuid', $data['project'])->firstOrFail();

        try {
            $this->reporting->addProject($period, $project);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['project' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.report_updated'));
    }

    /** Fill in what the invoicing add-on says has been invoiced. Asked for, never automatic. */
    public function pullInvoiced(Request $request, string $uuid, string $rowUuid): RedirectResponse
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', Project::class);
        abort_unless($request->user()->can('viewCosts', Project::class), 403);

        $row = MonthlyReport::where('reporting_period_id', $period->id)->where('uuid', $rowUuid)->firstOrFail();

        try {
            $this->reporting->pullInvoicedToDate($row);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invoiced' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.report_updated'));
    }

    public function issue(Request $request, string $uuid): RedirectResponse
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('manageSettings', Project::class);

        try {
            $this->reporting->issue($period);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.report_issued'));
    }

/**
     * The report as a document — PDF or workbook.
     *
     * A plain navigation rather than XHR, because the browser has to receive it as a download.
     * Printing is the PDF: a browser print of a table this wide is unusable, and the same landscape
     * document is what they file.
     */
    public function download(Request $request, string $uuid, string $format)
    {
        $period = ReportingPeriod::where('uuid', $uuid)->firstOrFail();
        $this->authorize('viewAny', Project::class);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);

        $canSeeCosts = $request->user()->can('viewCosts', Project::class);

        $rows = $period->reports()
            ->with(['project:id,uuid,code,name', 'cashflows'])
            ->whereIn('project_id', Project::visibleTo($request->user())->select('id'))
            ->get()
            ->values()
            ->map(fn (MonthlyReport $r, int $i) => $this->row($r, $i, $canSeeCosts))
            ->all();

        $stem = Str::slug($period->label() . '-project-and-financial-report');

        return $format === 'xlsx'
            ? $this->xlsx($stem, $period, $rows, $canSeeCosts)
            : $this->pdf($stem, $period, $rows, $canSeeCosts);
    }

    private function pdf(string $stem, ReportingPeriod $period, array $rows, bool $canSeeCosts)
    {
        $html = view('project-management.reporting.report', [
            'period' => [
                'name'            => $period->report_name,
                'label'           => $period->label(),
                'meeting_date'    => optional($period->meeting_date)->format('d F Y'),
                'general_comment' => $period->general_comment,
            ],
            'months'       => $period->months(),
            'rows'         => $rows,
            'summary'      => $canSeeCosts ? $this->reporting->summary($period) : null,
            'showFinances' => $canSeeCosts,
            'printed'      => now()->toDayDateTimeString(),
        ])->render();

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->download($stem . '.pdf');
    }

    private function xlsx(string $stem, ReportingPeriod $period, array $rows, bool $canSeeCosts)
    {
        $headers = ['No.', 'Project Name', 'Project Manager', 'Stage', 'Progress %', 'Project Status', 'Challenges'];

        if ($canSeeCosts) {
            $headers = array_merge($headers, [
                'Total estimated professional fee',
                'Total professional fee invoiced to date',
                'Total prof fee outstanding (incl. Disb.)',
            ], array_column($period->months(), 'label'));
        }

        $data = array_map(function (array $row) use ($period, $canSeeCosts): array {
            $line = [
                $row['no'], $row['project'], $row['project_manager_label'], $row['stage'],
                $row['progress_percentage'],
                trim(($row['project_status'] ?? '') . "\n" . ($row['project_status_notes'] ?? '')),
                $row['challenges'],
            ];

            if ($canSeeCosts) {
                $line = array_merge($line, [
                    $row['estimated_professional_fee'] ?? null,
                    $row['professional_fee_invoiced_to_date'] ?? null,
                    $row['professional_fee_outstanding'] ?? null,
                ]);

                foreach ($period->months() as $month) {
                    $line[] = $row['cashflow'][$month['key']] ?? null;
                }
            }

            return array_map([$this, 'safeCell'], $line);
        }, $rows);

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(Str::limit($period->label(), 28, ''));
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->fromArray($data, null, 'A2');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // The bullet lists need to wrap rather than run off the sheet.
        $sheet->getStyle('F2:G' . ($sheet->getHighestRow()))->getAlignment()->setWrapText(true);

        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, $stem . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Neutralise a cell a spreadsheet would execute.
     *
     * Challenges and status notes are free text typed by people, and a line starting `=` becomes a
     * formula in whatever opens the workbook. Same guard as the invoicing exports.
     */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

/**
     * A project manager's own rows, across every month.
     *
     * "My Monthly Project Reports" in the client's spec. The same data as the consolidated report,
     * narrowed to the projects this person is responsible for and to the columns they act on —
     * project, stage, progress, status, challenges and what is outstanding.
     */
    public function projectReports(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $canSeeCosts = $request->user()->can('viewCosts', Project::class);
        $filters = $this->filters($request);

        $rows = MonthlyReport::query()
            ->with(['project:id,uuid,code,name', 'period'])
            ->whereIn('project_id', Project::visibleTo($request->user())->select('id'))
            // Mine: named as a manager on the row, or the only projects I can see anyway.
            ->when(! $request->boolean('all'), fn ($q) => $q->where(
                fn ($w) => $w->whereJsonContains('project_manager_ids', $request->user()->id)
                    ->orWhereNull('project_manager_ids')
            ))
            ->when($filters['stage'], fn ($q, $v) => $q->where('stage', $v))
            ->when($filters['status'], fn ($q, $v) => $q->where('project_status', $v))
            ->when($filters['period'], fn ($q, $v) => $q->whereIn(
                'reporting_period_id', ReportingPeriod::where('uuid', $v)->select('id')
            ))
            ->when($filters['project'], fn ($q, $v) => $q->whereIn(
                'project_id', Project::where('uuid', $v)->select('id')
            ))
            ->latest('id')
            ->limit(500)
            ->get();

        return Inertia::render('Modules/ProjectManagement/Reporting/Projects', [
            'rows' => $rows->values()->map(fn (MonthlyReport $r, int $i) => [
                'id'         => $r->uuid,
                'month'      => $r->period?->label(),
                'period'     => $r->period?->uuid,
                'project'    => $r->project?->name,
                'project_code' => $r->project?->code,
                'stage'      => $r->stage,
                'progress_percentage' => $r->progress_percentage,
                'project_status' => $r->project_status,
                'challenges' => $r->challenges,
                'outstanding' => $canSeeCosts ? Money::money($r->professional_fee_outstanding) : null,
            ])->all(),
            'filters'  => $filters + ['all' => $request->boolean('all')],
            'periods'  => $this->periodOptions(),
            'projects' => $this->projectOptions($request),
            'stages'   => ReportingStages::stages(),
            'statuses' => ReportingStages::statuses(),
            'costs'    => $canSeeCosts,
        ]);
    }

    /**
     * The reporting dashboard.
     *
     * Only figures the client's own document has — projects, by stage, by status, average progress,
     * the three fee totals and the monthly cash flow. No invented KPIs, which was explicit in the
     * brief and is the reason there is no "health score" here.
     */
    public function dashboard(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);
        abort_unless($request->user()->can('viewCosts', Project::class), 403);

        $filters = $this->filters($request);

        $period = $filters['period']
            ? ReportingPeriod::where('uuid', $filters['period'])->first()
            : ReportingPeriod::orderByDesc('reporting_year')->orderByDesc('reporting_month')->first();

        return Inertia::render('Modules/ProjectManagement/Reporting/Dashboard', [
            'period'  => $period ? [
                'id' => $period->uuid, 'label' => $period->label(), 'name' => $period->report_name,
                'status' => $period->status,
            ] : null,
            'summary' => $period ? $this->reporting->summary($period, $filters) : null,
            /*
             * Deliberately unfiltered, and across periods rather than within one.
             *
             * The tiles answer "where are we this month"; this answers "which way is it going",
             * which no single month can. Applying the page's filters to it would draw a line whose
             * shape changed every time somebody picked a stage, and a trend that moves when you
             * filter is not a trend anybody should act on.
             */
            'trend'   => $this->reporting->trend(),
            'filters' => $filters,
            'periods' => $this->periodOptions(),
            'projects' => $this->projectOptions($request),
            'stages'   => ReportingStages::stages(),
            'statuses' => ReportingStages::statuses(),
            'people'   => $this->people(),
        ]);
    }

    /** The five filters the client's spec names. */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'period'  => ['nullable', 'uuid'],
            'project' => ['nullable', 'uuid'],
            'manager' => ['nullable', 'integer'],
            'stage'   => ['nullable', 'string', 'max:120'],
            'status'  => ['nullable', 'string', 'max:120'],
        ]);

        return [
            'period'  => $data['period'] ?? null,
            'project' => $data['project'] ?? null,
            'manager' => $data['manager'] ?? null,
            'stage'   => $data['stage'] ?? null,
            'status'  => $data['status'] ?? null,
        ];
    }

    private function periodOptions(): array
    {
        return ReportingPeriod::orderByDesc('reporting_year')->orderByDesc('reporting_month')
            ->limit(36)->get()
            ->map(fn (ReportingPeriod $p) => ['id' => $p->uuid, 'label' => $p->label()])
            ->all();
    }

    private function projectOptions(Request $request): array
    {
        return Project::visibleTo($request->user())->orderBy('name')->get(['uuid', 'code', 'name'])
            ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => $p->code . ' — ' . $p->name])
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function row(MonthlyReport $r, int $index, bool $canSeeCosts): array
    {
        $row = [
            // The "No." column: position on the report, not a database id.
            'no'                    => $r->row_number ?: $index + 1,
            'id'                    => $r->uuid,
            'project'               => $r->project?->name,
            'project_code'          => $r->project?->code,
            'project_id'            => $r->project?->uuid,
            'project_manager_ids'   => $r->project_manager_ids ?? [],
            'project_manager_label' => $r->managerLabel(),
            'stage'                 => $r->stage,
            'progress_percentage'   => $r->progress_percentage,
            'project_status'        => $r->project_status,
            'project_status_notes'  => $r->project_status_notes,
            'challenges'            => $r->challenges,
            'cashflow'              => $r->cashflows->mapWithKeys(
                fn (MonthlyCashflow $c) => [$c->key() => Money::money($c->amount)]
            )->all(),
        ];

        // Absent rather than null when the viewer may not see cost, the way the rest of the module
        // redacts money (PM-1021).
        if ($canSeeCosts) {
            $row['estimated_professional_fee'] = Money::money($r->estimated_professional_fee);
            $row['professional_fee_invoiced_to_date'] = Money::money($r->professional_fee_invoiced_to_date);
            $row['professional_fee_outstanding'] = Money::money($r->professional_fee_outstanding);

            /*
             * What the client actually owes, from the ledger — a different question from the two
             * fee columns beside it, and one this report could not previously answer.
             *
             * `professional_fee_outstanding` is the *estimated fee less what has been invoiced*: it
             * says how much billing is still to come. `debtor_outstanding` is money invoiced and
             * not yet received. A job can be fully billed with nothing left to invoice and still be
             * owed most of its value, and the report used to show only the first of those — which
             * reads as a finished job.
             *
             * Null rather than zero where no account on the project has reached Accounting: "we
             * cannot see" and "nothing is owed" are different answers and must not look alike.
             */
            $position = $r->project
                ? app(ProjectLedgerPosition::class)->forProject($r->project)
                : null;

            $row['debtor_invoiced']    = $position['available'] ?? false ? Money::money($position['invoiced']) : null;
            $row['debtor_paid']        = $position['available'] ?? false ? Money::money($position['paid']) : null;
            $row['debtor_outstanding'] = $position['available'] ?? false ? Money::money($position['outstanding']) : null;
            $row['debtor_overdue']     = $position['available'] ?? false ? Money::money($position['overdue']) : null;
        }

        return $row;
    }

    /**
     * Hub users the report can name as project managers.
     *
     * A real name where HR knows one, the email otherwise — a dropdown of raw addresses is not
     * something anybody can pick a project manager out of.
     */
    private function people(): array
    {
        $names = DB::table('hr_people')
            ->whereNotNull('user_id')
            ->selectRaw("user_id, trim(coalesce(first_name,'') || ' ' || coalesce(surname,'')) as full_name")
            ->pluck('full_name', 'user_id');

        return User::query()
            ->orderBy('email')
            ->limit(500)
            ->get(['id', 'email'])
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'label' => trim((string) ($names[$u->id] ?? '')) ?: $u->email,
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }
}
