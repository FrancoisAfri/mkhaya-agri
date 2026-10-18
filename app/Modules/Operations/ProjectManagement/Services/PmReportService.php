<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Baseline;
use App\Modules\Operations\ProjectManagement\Models\BaselineTask;
use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\EvidenceDeadLetter;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\CostEntry;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\ChangeRequest;
use App\Modules\Operations\ProjectManagement\Models\Issue;
use App\Modules\Operations\ProjectManagement\Models\Risk;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskRecurrence;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BI datasets for Project Management (EPIC-070).
 *
 * One tabular dataset per area of the module, each a flat header-plus-rows shape that goes straight
 * into Power BI, Excel or a warehouse without anyone unpicking nested JSON first. That flatness is
 * the whole point: a report nobody can load into their own tool is a report they will rebuild badly
 * from a database export.
 *
 * Nothing is recomputed here. Progress and cost come from the derivation cache, schedule figures
 * from the scheduler, hours from approved timesheets. A report that did its own arithmetic would
 * eventually disagree with the screen it was exported from, and the export is what reaches the
 * client.
 */
class PmReportService
{
    /** Rows per dataset on screen. The CSV export is unbounded — that is what it is for. */
    private const PREVIEW_LIMIT = 500;

    private ?CarbonImmutable $from = null;

    private ?CarbonImmutable $to = null;

    private ?int $projectId = null;

    /**
     * Every report, keyed, with the ribbon each belongs to and where its data is captured.
     *
     * Grouped by ribbon so the page can present them the way the module is organised rather than as
     * one undifferentiated list of twelve tabs.
     *
     * `source` is the answer to the question every empty report provokes — "where do I enter this?"
     * A BI page that shows an empty Labour Cost table and says nothing about how it gets filled is
     * a page that makes people think the feature is broken, when in fact nobody has been added to a
     * project yet. Each key resolves to a sentence in project_management.report_sources.*.
     *
     * @return array<string, array{label:string, area:string, source:string}>
     */
    public static function reportKeys(): array
    {
        return [
            'portfolio'         => ['label' => 'Portfolio',            'area' => 'projects', 'source' => 'projects'],
            'tasks'             => ['label' => 'Tasks',                'area' => 'work',     'source' => 'work'],
            'dependencies'      => ['label' => 'Dependencies',         'area' => 'work',     'source' => 'dependencies'],
            'overdue'           => ['label' => 'Overdue work',         'area' => 'work',     'source' => 'work'],
            'critical_path'     => ['label' => 'Critical path',        'area' => 'schedule', 'source' => 'schedule'],
            'schedule_variance' => ['label' => 'Baseline variance',    'area' => 'schedule', 'source' => 'baseline'],
            'recurrence'        => ['label' => 'Recurring work',       'area' => 'schedule', 'source' => 'recurrence'],
            'evidence_log'      => ['label' => 'Evidence log',         'area' => 'evidence', 'source' => 'evidence'],
            'evidence_summary'  => ['label' => 'Evidence by type',     'area' => 'evidence', 'source' => 'evidence'],
            'rejected_evidence' => ['label' => 'Rejected evidence',    'area' => 'evidence', 'source' => 'evidence'],
            'derived_figures'   => ['label' => 'Derived figures',      'area' => 'figures',  'source' => 'figures'],
            'timesheets'        => ['label' => 'Timesheets',           'area' => 'delivery', 'source' => 'timesheets'],
            'resource_load'     => ['label' => 'Resource allocation',  'area' => 'delivery', 'source' => 'assignments'],
            'labour_cost'       => ['label' => 'Labour cost',          'area' => 'delivery', 'source' => 'labour'],
            'cost_entries'      => ['label' => 'Materials & other cost', 'area' => 'finance',  'source' => 'costs'],
            'cost_by_project'   => ['label' => 'Cost position',         'area' => 'finance',  'source' => 'costs'],
            'profitability'     => ['label' => 'Profitability',         'area' => 'finance',  'source' => 'costs'],
            'risks'             => ['label' => 'Risk register',         'area' => 'governance', 'source' => 'registers'],
            'issues'            => ['label' => 'Issue register',        'area' => 'governance', 'source' => 'registers'],
            'changes'           => ['label' => 'Change register',       'area' => 'governance', 'source' => 'registers'],
            'team'              => ['label' => 'Team',                  'area' => 'resources', 'source' => 'members'],
            'allocation'        => ['label' => 'Allocation',            'area' => 'resources', 'source' => 'assignments'],
            'approvals'         => ['label' => 'Approvals',             'area' => 'governance', 'source' => 'approvals'],
            'linked_records'    => ['label' => 'Linked records',        'area' => 'governance', 'source' => 'links'],
        ];
    }

    public function withRange(?string $from, ?string $to): self
    {
        $this->from = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
        $this->to = $to ? CarbonImmutable::parse($to)->endOfDay() : null;

        return $this;
    }

    public function forProject(?int $projectId): self
    {
        $this->projectId = $projectId;

        return $this;
    }

    /**
     * Headline figures for the page.
     *
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $tasks = $this->scopedTasks();

        return [
            'projects'       => $this->scopedProjects()->count(),
            'tasks'          => (clone $tasks)->count(),
            'tasks_done'     => (clone $tasks)->where('status', 'done')->count(),
            'tasks_overdue'  => (clone $tasks)->open()->whereNotNull('planned_end')->where('planned_end', '<', now())->count(),
            'evidence'       => $this->scopedEvidence()->count(),
            'approved_hours' => round((float) $this->scopedTime()->approved()->sum('hours'), 2),
            'labour_cost'    => round((float) $this->scopedTime()->approved()->sum('cost_amount'), 2),
        ];
    }

    /**
     * One dataset.
     *
     * @param  bool  $full  true for the export, which is not truncated
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    public function dataset(string $key, bool $full = false): array
    {
        $limit = $full ? null : self::PREVIEW_LIMIT;

        return match ($key) {
            'portfolio'         => $this->portfolio($limit),
            'profitability'     => $this->profitability($limit),
            'risks'             => $this->risks($limit),
            'issues'            => $this->issues($limit),
            'changes'           => $this->changes($limit),
            'tasks'             => $this->tasks($limit),
            'dependencies'      => $this->dependencies($limit),
            'overdue'           => $this->overdue($limit),
            'critical_path'     => $this->criticalPath($limit),
            'schedule_variance' => $this->scheduleVariance($limit),
            'recurrence'        => $this->recurrence($limit),
            'evidence_log'      => $this->evidenceLog($limit),
            'evidence_summary'  => $this->evidenceSummary(),
            'rejected_evidence' => $this->rejectedEvidence($limit),
            'derived_figures'   => $this->derivedFigures($limit),
            'timesheets'        => $this->timesheets($limit),
            'resource_load'     => $this->resourceLoad($limit),
            'labour_cost'       => $this->labourCost(),
            'cost_entries'      => $this->costEntries($limit),
            'cost_by_project'   => $this->costByProject($limit),
            'team'              => $this->team($limit),
            'allocation'        => $this->allocation($limit),
            'approvals'         => $this->approvals($limit),
            'linked_records'    => $this->linkedRecords($limit),
            default             => [[], []],
        };
    }

    // ── Projects ─────────────────────────────────────────────────────────────

    private function portfolio(?int $limit): array
    {
        $rows = $this->scopedProjects()
            ->withCount(['tasks as task_count' => fn ($q) => $q->real()])
            ->withAvg(['tasks as progress' => fn ($q) => $q->real()], 'progress_pct')
            ->orderBy('code')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Project $p) => [
                $p->code, $p->name, $p->status, $p->stage,
                $p->planned_start?->toDateString(), $p->planned_end?->toDateString(),
                $p->actual_start?->toDateString(), $p->actual_end?->toDateString(),
                $p->currency, (float) $p->budget_amount,
                (int) $p->task_count, round((float) ($p->progress ?? 0), 1),
            ])
            ->all();

        return [
            ['Code', 'Name', 'Status', 'Stage', 'Planned start', 'Planned finish',
                'Actual start', 'Actual finish', 'Currency', 'Budget', 'Tasks', 'Progress %'],
            $rows,
        ];
    }

    // ── Work ─────────────────────────────────────────────────────────────────

    private function tasks(?int $limit): array
    {
        $rows = $this->scopedTasks()
            ->with('project:id,code')
            ->orderBy('project_id')->orderBy('code')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Task $t) => [
                $t->project?->code, $t->code, $t->name, $t->status, $t->priority,
                $t->planned_start?->toDateString(), $t->planned_end?->toDateString(),
                (float) $t->progress_pct, $t->progress_source,
                $t->is_critical ? 'yes' : 'no',
                $t->is_milestone ? 'yes' : 'no',
                (int) $t->duration_minutes,
                $t->planned_quantity === null ? null : (float) $t->planned_quantity,
                $t->quantity_unit,
            ])
            ->all();

        return [
            ['Project', 'Code', 'Name', 'Status', 'Priority', 'Planned start', 'Planned finish',
                'Progress %', 'Progress source', 'Critical', 'Milestone', 'Duration (min)',
                'Planned qty', 'Unit'],
            $rows,
        ];
    }

    private function dependencies(?int $limit): array
    {
        $rows = DB::table('pm_task_dependencies as d')
            ->join('pm_tasks as p', 'p.id', '=', 'd.predecessor_id')
            ->join('pm_tasks as s', 's.id', '=', 'd.successor_id')
            ->join('pm_projects as pr', 'pr.id', '=', 'd.project_id')
            ->whereNull('d.deleted_at')
            ->when($this->projectId, fn ($q) => $q->where('d.project_id', $this->projectId))
            ->select('pr.code as project', 'p.code as pred', 'p.name as pred_name',
                's.code as succ', 's.name as succ_name', 'd.dep_type', 'd.lag_minutes')
            ->orderBy('pr.code')->orderBy('p.code')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($r) => [$r->project, $r->pred, $r->pred_name, $r->succ, $r->succ_name,
                strtoupper($r->dep_type), (int) $r->lag_minutes])
            ->all();

        return [
            ['Project', 'Predecessor', 'Predecessor name', 'Successor', 'Successor name', 'Type', 'Lag (min)'],
            $rows,
        ];
    }

    private function overdue(?int $limit): array
    {
        $rows = $this->scopedTasks()
            ->open()
            ->whereNotNull('planned_end')
            ->where('planned_end', '<', now())
            ->with('project:id,code')
            ->orderBy('planned_end')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Task $t) => [
                $t->project?->code, $t->code, $t->name, $t->status,
                $t->planned_end?->toDateString(),
                // Whole days late, counted from the missed date to today so the number is
                // positive. The other way round reports overdue work as "-7 days late".
                (int) $t->planned_end->startOfDay()->diffInDays(now()->startOfDay()),
                (float) $t->progress_pct,
                $t->is_critical ? 'yes' : 'no',
            ])
            ->all();

        return [
            ['Project', 'Code', 'Name', 'Status', 'Was due', 'Days late', 'Progress %', 'Critical'],
            $rows,
        ];
    }

    // ── Schedule ─────────────────────────────────────────────────────────────

    private function criticalPath(?int $limit): array
    {
        $rows = $this->scopedTasks()
            ->where('is_critical', true)
            ->with('project:id,code')
            ->orderBy('project_id')->orderBy('early_start')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Task $t) => [
                $t->project?->code, $t->code, $t->name, $t->status,
                $t->early_start?->toDateString(), $t->early_finish?->toDateString(),
                $t->late_start?->toDateString(), $t->late_finish?->toDateString(),
                (int) $t->total_float_minutes, (int) $t->free_float_minutes,
            ])
            ->all();

        return [
            ['Project', 'Code', 'Name', 'Status', 'Earliest start', 'Earliest finish',
                'Latest start', 'Latest finish', 'Total float (min)', 'Free float (min)'],
            $rows,
        ];
    }

    /**
     * What has moved since the schedule was agreed.
     *
     * Positive days are late. The sign is kept rather than reported as a magnitude, because "three
     * days out" without a direction is not a fact anyone can act on.
     */
    private function scheduleVariance(?int $limit): array
    {
        $baselines = Baseline::query()
            ->current()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->with('project:id,code')
            ->get();

        $rows = [];

        foreach ($baselines as $baseline) {
            $current = Task::where('project_id', $baseline->project_id)->real()->get()->keyBy('id');

            foreach (BaselineTask::where('baseline_id', $baseline->id)->get() as $was) {
                $now = $was->task_id ? $current->get($was->task_id) : null;

                $variance = $was->planned_end && $now?->planned_end
                    ? (int) $was->planned_end->startOfDay()->diffInDays($now->planned_end->startOfDay(), false)
                    : null;

                $rows[] = [
                    $baseline->project?->code, $baseline->name,
                    $was->task_code, $was->name,
                    $was->planned_end?->toDateString(),
                    $now?->planned_end?->toDateString(),
                    $variance,
                    $now ? ($variance === null ? 'undated' : ($variance > 0 ? 'late' : ($variance < 0 ? 'early' : 'on plan'))) : 'dropped',
                ];

                if ($limit && count($rows) >= $limit) {
                    break 2;
                }
            }
        }

        return [
            ['Project', 'Baseline', 'Task', 'Name', 'Agreed finish', 'Current finish', 'Variance (days)', 'State'],
            $rows,
        ];
    }

    private function recurrence(?int $limit): array
    {
        $rows = TaskRecurrence::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->with(['project:id,code', 'template:id,code,name'])
            ->orderByDesc('is_active')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (TaskRecurrence $r) => [
                $r->project?->code, $r->template?->code, $r->template?->name,
                $r->rrule, $r->timezone,
                $r->starts_at?->toDateString(), $r->ends_at?->toDateString(),
                $r->is_active ? 'active' : 'stopped',
                (int) $r->generated_count,
                $r->last_occurrence_at?->toDateString(),
            ])
            ->all();

        return [
            ['Project', 'Template', 'Name', 'Rule', 'Timezone', 'From', 'Until', 'State', 'Generated', 'Filled to'],
            $rows,
        ];
    }

    // ── Evidence ─────────────────────────────────────────────────────────────

    private function evidenceLog(?int $limit): array
    {
        $rows = $this->scopedEvidence()
            ->with('project:id,code')
            ->orderByDesc('occurred_at')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (EvidenceEvent $e) => [
                $e->project?->code, $e->occurred_at?->toDateTimeString(), $e->occurred_tz,
                $e->event_type, $e->subject_type, $e->source_module, $e->actor_type,
                $e->quantity === null ? null : (float) $e->quantity, $e->unit,
                $e->amount === null ? null : (float) $e->amount, $e->currency,
                (float) $e->confidence,
                $e->superseded_by ? 'superseded' : 'live',
            ])
            ->all();

        return [
            ['Project', 'Occurred', 'Zone', 'Event', 'Subject', 'Source', 'Actor',
                'Quantity', 'Unit', 'Amount', 'Currency', 'Confidence', 'State'],
            $rows,
        ];
    }

    private function evidenceSummary(): array
    {
        $rows = $this->scopedEvidence()
            ->select('event_type', DB::raw('count(*) as total'),
                DB::raw('sum(case when superseded_by is null then 0 else 1 end) as superseded'),
                DB::raw('min(occurred_at) as first_seen'), DB::raw('max(occurred_at) as last_seen'))
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                $r->event_type,
                \App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy::domainOf($r->event_type) ?? 'unknown',
                (int) $r->total, (int) $r->superseded,
                $r->first_seen ? CarbonImmutable::parse($r->first_seen)->toDateString() : null,
                $r->last_seen ? CarbonImmutable::parse($r->last_seen)->toDateString() : null,
            ])
            ->all();

        return [['Event type', 'Domain', 'Recorded', 'Superseded', 'First', 'Latest'], $rows];
    }

    /** Data quality: what the ledger refused, and why. */
    private function rejectedEvidence(?int $limit): array
    {
        $rows = EvidenceDeadLetter::query()
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('created_at', '<=', $this->to))
            ->orderByDesc('created_at')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (EvidenceDeadLetter $d) => [
                $d->created_at?->toDateTimeString(),
                $d->source_module ?? null,
                $d->event_type ?? null,
                is_array($d->errors) ? implode('; ', $d->errors) : (string) $d->errors,
                // Whether anyone acted on it. A rejection list without this is a list of things
                // that look outstanding forever.
                $d->replayed_at ? 'replayed ' . $d->replayed_at->toDateString() : 'outstanding',
            ])
            ->all();

        return [['Rejected at', 'Source', 'Event', 'Why', 'Outcome'], $rows];
    }

    // ── Figures ──────────────────────────────────────────────────────────────

    private function derivedFigures(?int $limit): array
    {
        $taskIds = $this->scopedTasks()->select('id');

        $rows = DerivedValue::query()
            ->where('subject_type', 'task')
            ->whereIn('subject_id', $taskIds)
            ->join('pm_tasks', 'pm_tasks.id', '=', 'pm_derived_values.subject_id')
            ->join('pm_projects', 'pm_projects.id', '=', 'pm_tasks.project_id')
            ->select('pm_projects.code as project', 'pm_tasks.code as task', 'pm_tasks.name as task_name',
                'pm_derived_values.*')
            ->orderBy('pm_projects.code')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($r) => [
                $r->project, $r->task, $r->task_name, $r->metric,
                $r->value_numeric === null ? null : (float) $r->value_numeric,
                $r->value_text,
                (float) $r->confidence, $r->method_version, (int) $r->evidence_count,
                $r->is_overridden ? 'yes' : 'no', $r->override_reason,
                $r->computed_at ? CarbonImmutable::parse($r->computed_at)->toDateTimeString() : null,
            ])
            ->all();

        return [
            ['Project', 'Task', 'Name', 'Metric', 'Value', 'Text', 'Confidence',
                'Method', 'Events behind it', 'Overridden', 'Override reason', 'Computed'],
            $rows,
        ];
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    private function timesheets(?int $limit): array
    {
        $rows = $this->scopedTime()
            ->with(['project:id,code', 'task:id,code,name', 'member:id,display_name'])
            ->orderByDesc('worked_on')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (TimeEntry $e) => [
                $e->project?->code, $e->member?->display_name,
                $e->task?->code, $e->task?->name,
                $e->worked_on?->toDateString(), (float) $e->hours, $e->status,
                $e->rate_per_hour === null ? null : (float) $e->rate_per_hour,
                $e->cost_amount === null ? null : (float) $e->cost_amount,
                $e->currency, $e->approved_at?->toDateString(),
            ])
            ->all();

        return [
            ['Project', 'Person', 'Task', 'Task name', 'Worked on', 'Hours', 'Status',
                'Rate', 'Cost', 'Currency', 'Approved'],
            $rows,
        ];
    }

    private function resourceLoad(?int $limit): array
    {
        $rows = TaskAssignment::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->with(['project:id,code', 'task:id,code,name,planned_start,planned_end', 'member:id,display_name,role_key'])
            ->orderBy('member_id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (TaskAssignment $a) => [
                $a->project?->code, $a->member?->display_name, $a->member?->role_key,
                $a->task?->code, $a->task?->name,
                $a->task?->planned_start?->toDateString(), $a->task?->planned_end?->toDateString(),
                (int) $a->allocation_pct, $a->is_lead ? 'yes' : 'no', $a->role,
            ])
            ->all();

        return [
            ['Project', 'Person', 'Project role', 'Task', 'Task name', 'From', 'To',
                'Allocation %', 'Lead', 'Task role'],
            $rows,
        ];
    }

    private function labourCost(): array
    {
        $rows = $this->scopedTime()
            ->join('pm_projects', 'pm_projects.id', '=', 'pm_time_entries.project_id')
            ->select('pm_projects.code as project', 'pm_time_entries.currency',
                DB::raw("sum(case when pm_time_entries.status = 'approved' then pm_time_entries.hours else 0 end) as approved_hours"),
                DB::raw("sum(case when pm_time_entries.status <> 'approved' then pm_time_entries.hours else 0 end) as pending_hours"),
                DB::raw("sum(case when pm_time_entries.status = 'approved' then pm_time_entries.cost_amount else 0 end) as cost"))
            ->groupBy('pm_projects.code', 'pm_time_entries.currency')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($r) => [
                $r->project, $r->currency,
                round((float) $r->approved_hours, 2),
                round((float) $r->pending_hours, 2),
                round((float) $r->cost, 2),
            ])
            ->all();

        return [['Project', 'Currency', 'Approved hours', 'Unapproved hours', 'Approved cost'], $rows];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every non-labour cost, with what happened to it.
     *
     * The status column is the point: a materials figure that silently mixed drafts with approved
     * spend would be a number nobody could reconcile against the ledger.
     */
    private function costEntries(?int $limit): array
    {
        $rows = CostEntry::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->when($this->from, fn ($q) => $q->where('incurred_on', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('incurred_on', '<=', $this->to))
            ->with(['project:id,code', 'task:id,code'])
            ->orderByDesc('incurred_on')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (CostEntry $e) => [
                $e->project?->code, $e->incurred_on?->toDateString(), $e->category, $e->description,
                (float) $e->amount, $e->currency, $e->status, $e->invoice_number,
                $e->task?->code, $e->is_billable ? 'yes' : 'no', $e->posted_reference,
            ])->all();

        return [
            ['Project', 'Date', 'Kind', 'What it was for', 'Amount', 'Currency', 'Status',
                'Invoice', 'Task', 'Billable', 'Ledger reference'],
            $rows,
        ];
    }

    /**
     * Budget, labour, materials and what is left, per project.
     *
     * The one row a project manager is actually asked about, and the reason it is worth a dataset
     * of its own rather than three that have to be joined by hand in a spreadsheet.
     */
    private function costByProject(?int $limit): array
    {
        $projects = $this->scopedProjects()->orderBy('code')->when($limit, fn ($q) => $q->limit($limit))->get();

        $labour = TimeEntry::query()->approved()
            ->select('project_id', DB::raw('sum(cost_amount) as total'))
            ->groupBy('project_id')->pluck('total', 'project_id');

        $paid = CostEntry::query()->paid()
            ->select('project_id', DB::raw('sum(amount) as total'))
            ->groupBy('project_id')->pluck('total', 'project_id');

        $committed = CostEntry::query()->committed()
            ->select('project_id', DB::raw('sum(amount) as total'))
            ->groupBy('project_id')->pluck('total', 'project_id');

        $rows = $projects->map(function (Project $p) use ($labour, $paid, $committed) {
            $budget = (float) $p->budget_amount;
            $spent = (float) ($labour[$p->id] ?? 0) + (float) ($paid[$p->id] ?? 0);
            $owed = (float) ($committed[$p->id] ?? 0);

            return [
                $p->code, $p->name, $p->status, $budget,
                round((float) ($labour[$p->id] ?? 0), 2),
                round((float) ($paid[$p->id] ?? 0), 2),
                round($owed, 2),
                round($budget - $spent - $owed, 2),
                // Null rather than zero without a budget: "0% spent" reads as on track when in
                // fact nobody has said what the job may cost.
                $budget > 0 ? round((($spent + $owed) / $budget) * 100, 1) : null,
                $p->currency,
            ];
        })->all();

        return [
            ['Project', 'Name', 'Status', 'Budget', 'Labour', 'Materials & other',
                'Committed', 'Remaining', 'Spent %', 'Currency'],
            $rows,
        ];
    }

    /** Who is on which project, in what role. @return array{0:list<string>,1:list<list<mixed>>} */
    private function team(?int $limit): array
    {
        $rows = ProjectMember::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->with(['project:id,code,name', 'externalRef:id,source_module,source_id'])
            ->orderBy('project_id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (ProjectMember $m) => [
                $m->project?->code, $m->display_name, $m->role_key, $m->email,
                $m->is_active ? 'yes' : 'no',
                // Where the person's identity comes from — an HR employee or a name PM holds.
                $m->externalRef?->source_module ?: 'local',
                TaskAssignment::where('member_id', $m->id)->count(),
            ])->all();

        return [
            ['Project', 'Person', 'Project role', 'Email', 'Active', 'Identity from', 'Tasks'],
            $rows,
        ];
    }

    /**
     * Every open assignment with its allocation, and whether the person is over-committed.
     *
     * The over-committed flag is computed across *all* their open work, not this row's: 60% here is
     * fine on its own and is the third of three that breaks the plan.
     */
    private function allocation(?int $limit): array
    {
        $totals = TaskAssignment::query()
            ->join('pm_tasks', 'pm_tasks.id', '=', 'pm_task_assignments.task_id')
            ->whereNull('pm_tasks.deleted_at')
            ->whereNotIn('pm_tasks.status', ['done', 'cancelled'])
            ->select('member_id', DB::raw('sum(allocation_pct) as allocation'))
            ->groupBy('member_id')
            ->pluck('allocation', 'member_id');

        $rows = TaskAssignment::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->with(['project:id,code', 'member:id,display_name,role_key', 'task:id,code,name,status,planned_start,planned_end'])
            ->orderBy('member_id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (TaskAssignment $a) => [
                $a->project?->code, $a->member?->display_name, $a->task?->code, $a->task?->name,
                $a->task?->status, (int) $a->allocation_pct,
                (int) ($totals[$a->member_id] ?? 0),
                ($totals[$a->member_id] ?? 0) > 100 ? 'yes' : 'no',
                $a->is_lead ? 'yes' : 'no',
                $a->task?->planned_start?->toDateString(), $a->task?->planned_end?->toDateString(),
            ])->all();

        return [
            ['Project', 'Person', 'Task', 'Task name', 'Task status', 'This task %',
                'All open work %', 'Over-committed', 'Lead', 'From', 'To'],
            $rows,
        ];
    }

    /** The approval trail: what was submitted, who decided, and how long it took. */
    private function approvals(?int $limit): array
    {
        $rows = ApprovalRequest::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('created_at', '<=', $this->to))
            ->with(['project:id,code', 'flow:id,name', 'actions'])
            ->orderByDesc('created_at')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(function (ApprovalRequest $r) {
                $decision = $r->actions->last();

                return [
                    $r->project?->code, $r->subject_type, $r->subject_label,
                    $r->amount !== null ? (float) $r->amount : null, $r->currency,
                    $r->status, $r->flow?->name ?: 'Default',
                    $r->created_at?->toDateTimeString(),
                    $r->decided_at?->toDateTimeString(),
                    // Hours from submission to decision, or how long it has waited so far.
                    (int) ($r->created_at?->diffInHours($r->decided_at ?? now()) ?? 0),
                    $decision?->actor_name,
                    $r->decision_reason,
                ];
            })->all();

        return [
            ['Project', 'What', 'Description', 'Amount', 'Currency', 'Status', 'Flow',
                'Submitted', 'Decided', 'Hours taken', 'Decided by', 'Reason'],
            $rows,
        ];
    }

    /**
     * What each project is attached to in the other modules.
     *
     * Includes links whose record has gone, marked as such — an orphaned link is a fact about the
     * project, and dropping it from the export would lose it silently.
     */
    private function linkedRecords(?int $limit): array
    {
        $projects = $this->scopedProjects()->pluck('code', 'id');

        $rows = ExternalRef::query()
            ->where('local_type', 'project_link')
            ->when($this->projectId, fn ($q) => $q->where('local_id', $this->projectId))
            ->orderBy('local_id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (ExternalRef $r) => [
                $projects[$r->local_id] ?? null,
                $r->snapshot['kind'] ?? $r->source_entity,
                $r->source_module,
                $r->display_name,
                $r->source_id,
                $r->sync_status,
                $r->last_synced_at?->toDateTimeString(),
            ])->all();

        return [
            ['Project', 'Kind', 'Module', 'Record', 'Source id', 'Status', 'Last checked'],
            $rows,
        ];
    }

    private function scopedProjects()
    {
        return Project::query()->when($this->projectId, fn ($q) => $q->where('id', $this->projectId));
    }

    private function scopedTasks()
    {
        return Task::query()
            ->real()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            // The date filter applies to when the work is planned, not when the row was written:
            // "March" means the work in March, which is what anyone asking for it means.
            ->when($this->from, fn ($q) => $q->where(fn ($w) => $w->whereNull('planned_end')->orWhere('planned_end', '>=', $this->from)))
            ->when($this->to, fn ($q) => $q->where(fn ($w) => $w->whereNull('planned_start')->orWhere('planned_start', '<=', $this->to)));
    }

    private function scopedEvidence()
    {
        return EvidenceEvent::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->when($this->from, fn ($q) => $q->where('occurred_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('occurred_at', '<=', $this->to));
    }

    private function scopedTime()
    {
        return TimeEntry::query()
            ->when($this->projectId, fn ($q) => $q->where('project_id', $this->projectId))
            ->when($this->from, fn ($q) => $q->where('worked_on', '>=', $this->from->toDateString()))
            ->when($this->to, fn ($q) => $q->where('worked_on', '<=', $this->to->toDateString()));
    }

    /**
     * What each project is worth against what it has cost (§26).
     *
     * Margin is budget minus actual, not revenue minus cost: this module does not hold what was
     * invoiced, and inventing revenue from a budget would produce a profit figure the finance team
     * would have to spend an afternoon disproving. Where Finance owns a budget, that is the number
     * used — the same precedence as everywhere else in the module.
     *
     * @return array{0:list<string>, 1:list<list<mixed>>}
     */
    private function profitability(?int $limit): array
    {
        $costs = app(CostService::class);

        $rows = Project::query()
            ->orderBy('code')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(function (Project $p) use ($costs) {
                $position = $costs->forProject($p);
                $budget = (float) ($position['budget'] ?? 0);
                $actual = (float) ($position['actual'] ?? 0);
                $committed = (float) ($position['committed'] ?? 0);

                return [
                    $p->code,
                    $p->name,
                    $p->status,
                    round($budget, 2),
                    round($actual, 2),
                    round($committed, 2),
                    // CostService already worked this out; recomputing it here would be a second
                    // answer to the same question, and the two would drift.
                    round((float) $position['remaining'], 2),
                    // Null rather than zero on a project with no budget: a margin percentage
                    // against nothing is a division nobody should read.
                    $budget > 0 ? round(((float) $position['remaining'] / $budget) * 100, 1) : null,
                ];
            })->all();

        return [
            ['Code', 'Project', 'Status', 'Budget', 'Actual', 'Committed', 'Margin', 'Margin %'],
            $rows,
        ];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function risks(?int $limit): array
    {
        $rows = Risk::query()
            ->with('project:id,code')
            ->orderByDesc('score')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Risk $r) => [
                $r->project?->code, $r->code, $r->title, $r->category,
                $r->probability, $r->impact, $r->score, $r->band(),
                $r->response_strategy, $r->status,
                optional($r->review_due)->toDateString(),
            ])->all();

        return [
            ['Project', 'Ref', 'Risk', 'Category', 'Probability', 'Impact', 'Score', 'Band', 'Response', 'Status', 'Review due'],
            $rows,
        ];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function issues(?int $limit): array
    {
        $rows = Issue::query()
            ->with('project:id,code')
            ->orderBy('status')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Issue $i) => [
                $i->project?->code, $i->code, $i->title, $i->kind, $i->severity, $i->status,
                optional($i->due_date)->toDateString(),
                optional($i->resolved_at)->toDateString(),
                $i->root_cause,
            ])->all();

        return [
            ['Project', 'Ref', 'Issue', 'Type', 'Severity', 'Status', 'Due', 'Resolved', 'Root cause'],
            $rows,
        ];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function changes(?int $limit): array
    {
        $rows = ChangeRequest::query()
            ->with('project:id,code')
            ->orderByDesc('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (ChangeRequest $c) => [
                $c->project?->code, $c->code, $c->title, $c->kind, $c->status,
                $c->impact_cost !== null ? (float) $c->impact_cost : null,
                $c->impact_days,
                optional($c->decided_at)->toDateString(),
            ])->all();

        return [
            ['Project', 'Ref', 'Change', 'Type', 'Status', 'Cost impact', 'Days', 'Decided'],
            $rows,
        ];
    }
}
