<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoicingSetting;
use App\Modules\Operations\ProjectManagement\Notifications\MonthlyReportIssuedNotification;
use App\Modules\Operations\ProjectManagement\Models\MonthlyCashflow;
use App\Modules\Operations\ProjectManagement\Models\MonthlyReport;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ReportingPeriod;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * The monthly Project & Financial Report.
 *
 * One period per month, one row per project, and a six-month cash-flow window that moves on by
 * itself. The two rules that shape everything here:
 *
 *   **Each month is its own record.** August keeps saying what it said when September is created.
 *   The document's value is that a project reads differently from one month to the next, so
 *   editing rows in place would destroy the only thing it is for.
 *
 *   **The project record stays the master.** A row points at a project and stores only what the
 *   client's report says that the project does not — their stage word, their status word, the
 *   challenges text and the three fee figures.
 */
class MonthlyReportingService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Open a month's report.
     *
     * With `copyFrom` it starts from the previous month — the client's "Copy Previous Month" —
     * bringing every row forward with its stage, status, challenges and figures so the user edits
     * what changed rather than retyping forty projects. The month copied from is untouched.
     */
    public function open(int $year, int $month, array $attributes = [], ?ReportingPeriod $copyFrom = null): ReportingPeriod
    {
        return DB::transaction(function () use ($year, $month, $attributes, $copyFrom) {
            $existing = ReportingPeriod::where('reporting_year', $year)
                ->where('reporting_month', $month)
                ->first();

            if ($existing) {
                throw new RuntimeException(
                    $existing->label() . ' already has a report. Open it rather than starting a second one.'
                );
            }

            $period = ReportingPeriod::create(array_merge([
                'reporting_year'  => $year,
                'reporting_month' => $month,
                'report_name'     => CarbonImmutable::create($year, $month, 1)->format('F Y')
                    . ' Monthly Project & Financial Report',
                'cashflow_months' => $copyFrom->cashflow_months ?? ReportingPeriod::DEFAULT_CASHFLOW_MONTHS,
                'status'          => 'draft',
                'created_by'      => Auth::id(),
            ], $attributes));

            $copyFrom
                ? $this->copyRows($period, $copyFrom)
                : $this->seedFromProjects($period);

            $this->audit->recordAction($period, 'period.opened', [
                'month'     => $period->label(),
                'copied_from' => $copyFrom?->label(),
            ]);

            return $period->fresh();
        });
    }

    /**
     * What the "new report" dialog should open on, and whether there is a month to copy.
     *
     * Not simply today. One report a month is the rule `open()` enforces, so once this month has
     * been opened, defaulting the dialog to it offers the one thing that cannot be done — the save
     * comes back "already has a report" and reads as the dialog being broken.
     *
     * So: the first month from today onward that has no report yet. The search is bounded because
     * an estate that has somehow opened reports two years into the future should get a sensible
     * default rather than a loop; the user can still pick any month by hand.
     *
     * @return array{year:int, month:int, has_previous:bool}
     */
    public function nextOpenMonth(?\DateTimeInterface $from = null): array
    {
        $month = ($from ? CarbonImmutable::parse($from) : CarbonImmutable::now())->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $taken = ReportingPeriod::where('reporting_year', $month->year)
                ->where('reporting_month', $month->month)
                ->exists();

            if (! $taken) {
                break;
            }

            $month = $month->addMonth();
        }

        return [
            'year'  => (int) $month->year,
            'month' => (int) $month->month,
            // Whether the checkbox has anything to offer, for the month actually being suggested.
            'has_previous' => $this->previousPeriod((int) $month->year, (int) $month->month) !== null,
        ];
    }

    /** The most recent month before this one that has a report, which is what to copy from. */
    public function previousPeriod(int $year, int $month): ?ReportingPeriod
    {
        return ReportingPeriod::query()
            ->whereRaw('(reporting_year * 12 + reporting_month) < ?', [$year * 12 + $month])
            ->orderByRaw('reporting_year desc, reporting_month desc')
            ->first();
    }

    /**
     * A first report starts from the live projects rather than from a blank page.
     *
     * Only active work: a cancelled or archived job is not something a monthly management report
     * carries a row for, and forty rows of finished projects is how the report stops being read.
     */
    private function seedFromProjects(ReportingPeriod $period): void
    {
        $projects = Project::query()
            ->whereIn('status', ['active', 'on_hold'])
            ->orderBy('name')
            ->get();

        foreach ($projects->values() as $index => $project) {
            $this->addProject($period, $project, $index + 1);
        }
    }

    /** Bring last month's rows forward, figures and all. The month copied from is not touched. */
    private function copyRows(ReportingPeriod $period, ReportingPeriod $source): void
    {
        foreach ($source->reports()->with('cashflows')->get() as $row) {
            $copy = MonthlyReport::create([
                'reporting_period_id'               => $period->id,
                'project_id'                        => $row->project_id,
                'row_number'                        => $row->row_number,
                'project_manager_ids'               => $row->project_manager_ids,
                'project_manager_label'             => $row->project_manager_label,
                'stage'                             => $row->stage,
                'progress_percentage'               => $row->progress_percentage,
                'project_status'                    => $row->project_status,
                'challenges'                        => $row->challenges,
                'estimated_professional_fee'        => $row->estimated_professional_fee,
                'professional_fee_invoiced_to_date' => $row->professional_fee_invoiced_to_date,
                'professional_fee_outstanding'      => $row->professional_fee_outstanding,
                'currency'                          => $row->currency,
                'created_by'                        => Auth::id(),
            ]);

            // Only the months this period's window actually shows: copying a projection for a
            // month that has rolled out of view would leave a figure nobody can see or edit.
            $window = collect($period->months())->keyBy('key');

            foreach ($row->cashflows as $cashflow) {
                if ($window->has($cashflow->key())) {
                    MonthlyCashflow::create([
                        'monthly_report_id' => $copy->id,
                        'forecast_year'     => $cashflow->forecast_year,
                        'forecast_month'    => $cashflow->forecast_month,
                        'amount'            => $cashflow->amount,
                    ]);
                }
            }
        }
    }

    /** Put a project on this month's report. */
    public function addProject(ReportingPeriod $period, Project $project, ?int $rowNumber = null): MonthlyReport
    {
        $this->refuseIfIssued($period);

        return MonthlyReport::firstOrCreate(
            ['reporting_period_id' => $period->id, 'project_id' => $project->id],
            [
                'row_number' => $rowNumber ?? ((int) MonthlyReport::where('reporting_period_id', $period->id)->max('row_number') + 1),
                'stage'      => $project->stage,
                'currency'   => $project->currency ?? 'ZAR',
                'created_by' => Auth::id(),
            ],
        );
    }

    /** Set one month's projected amount on a row. */
    public function setCashflow(MonthlyReport $row, int $year, int $month, string|float|int|null $amount): void
    {
        $this->refuseIfIssued($row->period);

        MonthlyCashflow::updateOrCreate(
            ['monthly_report_id' => $row->id, 'forecast_year' => $year, 'forecast_month' => $month],
            ['amount' => Money::money($amount)],
        );
    }

    /**
     * Fill in what has actually been invoiced, from the invoicing add-on.
     *
     * Explicitly asked for rather than applied automatically: the client's process is to type these
     * figures, and quietly replacing them with a computed number would change how the report is
     * produced without anybody deciding to. Pressing the button is that decision.
     */
    public function pullInvoicedToDate(MonthlyReport $row): string
    {
        $this->refuseIfIssued($row->period);

        $invoiced = Invoice::forProject($row->project_id)->claimable()->sum('amount_now_due');
        $total = Money::money($invoiced);

        $row->update([
            'professional_fee_invoiced_to_date' => $total,
            // Outstanding follows only when an estimate has been stated; otherwise it would read
            // as a negative amount owing.
            'professional_fee_outstanding' => $row->estimated_professional_fee !== null
                ? Money::money(Money::sub($row->estimated_professional_fee, $total))
                : $row->professional_fee_outstanding,
        ]);

        return $total;
    }

    /** Issue the report. After this the month is a record and stops moving. */
    public function issue(ReportingPeriod $period): ReportingPeriod
    {
        $this->refuseIfIssued($period);

        $period->update(['status' => 'issued', 'issued_at' => now()]);

        $this->audit->recordAction($period, 'period.issued', [
            'month' => $period->label(),
            'rows'  => $period->reports()->count(),
        ]);

        $this->notifyRecipients($period);

        return $period->fresh();
    }

/**
     * Tell the people who need the report the moment it is ready.
     *
     * Mostly executives who never open Project Management. Failures are swallowed on purpose: a
     * mail server that is down must not leave the report unissued, and the report being issued is
     * the fact that matters. The audit log records the issue either way.
     */
    private function notifyRecipients(ReportingPeriod $period): void
    {
        $settings = InvoicingSetting::current();
        $summary = $this->summary($period);
        $url = url('/project-management/reporting/reports/' . $period->uuid);

        $notification = new MonthlyReportIssuedNotification($period, $summary, $url);

        try {
            $users = User::whereIn('id', $settings->report_recipient_ids ?? [])->get();

            if ($users->isNotEmpty()) {
                Notification::send($users, $notification);
            }

            foreach ($settings->extraRecipientEmails() as $email) {
                // On-demand: these people have no Hub account, so there is nothing to store
                // an in-app notification against — they get the email only.
                Notification::route('mail', $email)->notify($notification);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function refuseIfIssued(?ReportingPeriod $period): void
    {
        if ($period?->isIssued()) {
            throw new RuntimeException(
                $period->label() . ' has been issued and can no longer be changed.'
            );
        }
    }

    /**
     * The dashboard figures, from the fields the client's document actually has.
     *
     * No invented KPIs: every number here is a column on their report.
     *
     * @return array<string, mixed>
     */
    public function summary(ReportingPeriod $period, array $filters = []): array
    {
        $rows = $period->reports()
            // Named, because top_outstanding reads each row's project and a lazy load here is one
            // query per project on a page that already summarises the whole portfolio.
            ->with('project:id,uuid,code,name')
            ->when($filters['project'] ?? null, fn ($q, $v) => $q->whereIn(
                'project_id', Project::where('uuid', $v)->select('id')
            ))
            ->when($filters['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('project_status', $v))
            ->when($filters['manager'] ?? null, fn ($q, $v) => $q->whereJsonContains('project_manager_ids', (int) $v))
            ->get();

        return [
            'projects'      => $rows->count(),
            'average_progress' => $rows->count()
                ? round((float) $rows->avg('progress_percentage'), 1)
                : null,
            'estimated_fee' => Money::money($rows->sum('estimated_professional_fee')),
            'invoiced'      => Money::money($rows->sum('professional_fee_invoiced_to_date')),
            'outstanding'   => Money::money($rows->sum('professional_fee_outstanding')),
            'by_stage'      => $rows->groupBy(fn ($r) => $r->stage ?: '—')->map->count()->all(),
            'by_status'     => $rows->groupBy(fn ($r) => $r->project_status ?: '—')->map->count()->all(),
            'cashflow'      => $this->cashflowTotals($period),

            /*
             * How the work is spread, rather than one average.
             *
             * An average progress of 55% describes a portfolio where everything is half done and a
             * portfolio where half is finished and half has not started, and those are opposite
             * situations. The bands say which one this is.
             */
            'progress_bands' => $this->progressBands($rows),

            /*
             * Where the outstanding money actually sits.
             *
             * A total tells you how much is owed; it does not tell you that three quarters of it is
             * one project. That is the difference between a collections problem and a client
             * problem, and it is the first thing anybody asks after seeing the total.
             */
            'top_outstanding' => $rows
                ->filter(fn ($r) => (float) $r->professional_fee_outstanding > 0)
                ->sortByDesc(fn ($r) => (float) $r->professional_fee_outstanding)
                ->take(5)
                ->map(fn ($r) => [
                    'project' => $r->project?->name ?? '—',
                    'code'    => $r->project?->code,
                    'amount'  => Money::money($r->professional_fee_outstanding),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The last twelve months of the figures that move, oldest first.
     *
     * A month on its own says what is true today and nothing about direction. Outstanding of two
     * million is a fact; outstanding of two million after four months of one and a half is a
     * different fact, and it is the one that gets acted on.
     *
     * Periods with no reports are included rather than skipped — a month nobody filled in is part
     * of the story, and a line that quietly closes the gap draws a trend that did not happen.
     *
     * @return list<array{label: string, estimated: float, invoiced: float, outstanding: float}>
     */
    public function trend(int $months = 12): array
    {
        $periods = ReportingPeriod::query()
            ->orderByDesc('reporting_year')->orderByDesc('reporting_month')
            ->limit($months)
            ->get()
            ->reverse()
            ->values();

        if ($periods->isEmpty()) {
            return [];
        }

        // One query for every period's reports, rather than one per period.
        $totals = MonthlyReport::query()
            ->whereIn('reporting_period_id', $periods->pluck('id'))
            ->get()
            ->groupBy('reporting_period_id');

        return $periods->map(function (ReportingPeriod $period) use ($totals) {
            $rows = $totals->get($period->id, collect());

            return [
                'label'       => $period->label(),
                'estimated'   => Money::money($rows->sum('estimated_professional_fee')),
                'invoiced'    => Money::money($rows->sum('professional_fee_invoiced_to_date')),
                'outstanding' => Money::money($rows->sum('professional_fee_outstanding')),
            ];
        })->all();
    }

    /**
     * Projects grouped by how far along they are.
     *
     * The bands are quarters, and the top one is "complete or nearly": a project at 97% is finished
     * as far as a monthly report is concerned, and a band that ends at 99 puts it with the ones
     * still being built.
     *
     * @return array<string, int>
     */
    private function progressBands($rows): array
    {
        $bands = ['not_started' => 0, 'early' => 0, 'halfway' => 0, 'most' => 0, 'complete' => 0];

        foreach ($rows as $row) {
            $percent = (float) ($row->progress_percentage ?? 0);

            $key = match (true) {
                $percent <= 0   => 'not_started',
                $percent < 25   => 'early',
                $percent < 50   => 'halfway',
                $percent < 95   => 'most',
                default         => 'complete',
            };

            $bands[$key]++;
        }

        return $bands;
    }

    /** The month columns totalled down the report, which is the cash-flow line management reads. */
    private function cashflowTotals(ReportingPeriod $period): array
    {
        $amounts = MonthlyCashflow::query()
            ->whereIn('monthly_report_id', $period->reports()->select('id'))
            ->get()
            ->groupBy(fn (MonthlyCashflow $c) => $c->key());

        return collect($period->months())->map(fn (array $month) => [
            'key'    => $month['key'],
            'label'  => $month['label'],
            'amount' => Money::money($amounts->get($month['key'], collect())->sum('amount')),
        ])->all();
    }
}
