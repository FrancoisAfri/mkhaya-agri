<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\CostEntry;
use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use Carbon\CarbonImmutable;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the charts (PM-1250).
 *
 * Every figure is aggregated in the database. The module's performance budget is measured against
 * 20,000 tasks and millions of evidence rows, and a dashboard that hydrates models to count them
 * stops meeting it long before that — the landing page would be the slowest thing in the module.
 *
 * Nothing here computes a *new* truth. Progress comes from the derivation cache, cost from approved
 * timesheets and the ledger, dates from the scheduler. A dashboard that did its own arithmetic
 * would eventually disagree with the pages it links to, and the dashboard would be the one nobody
 * trusts.
 */
class PmAnalyticsService
{
    /** How many months of history the trend charts cover. */
    private const TREND_MONTHS = 12;

    /** Long enough to collapse a burst of page loads, short enough that nobody notices. */
    private const CACHE_TTL = 60;

    /**
     * Charts that carry money.
     *
     * One list, next to where the charts are defined, because the redaction used to be copied into
     * the dashboard controller and the analytics controller — and the second copy is the one that
     * silently misses the chart somebody adds next.
     */
    private const COST_CHARTS = [
        'budget_vs_actual', 'cost_by_category', 'cost_trend', 'cost_position',
        'earned_value', 'cash_flow',
    ];

    /**
     * Everything the dashboard charts need, in one pass.
     *
     * `$withCosts` is the `pm.costs.view` grant. When it is false the money is **absent from the
     * payload**, not hidden in the page: a subcontractor with a login may see the programme and has
     * no business seeing the margin, and a hidden chart is still readable in the page source.
     *
     * @return array<string, mixed>
     */
    public function dashboard(bool $withCosts = true): array
    {
        // Cached briefly, per tenant. Twenty-one datasets is roughly forty queries, and a landing
        // page that re-runs all of them on every load is the slowest thing in the module — on a
        // database where planning under row-level security costs real time, it was timing the
        // request out entirely.
        //
        // A minute of staleness is invisible on a portfolio view: nobody makes a decision on the
        // difference between a chart drawn now and one drawn fifty seconds ago, and anything that
        // needs to be current (a task list, an approval queue) is not on this page.
        $charts = Cache::remember(
            'pm.analytics.' . (app(CurrentCompany::class)->id() ?? 'platform'),
            self::CACHE_TTL,
            fn () => $this->allCharts(),
        );

        // The redaction is applied *after* the cache, never inside it. Caching a redacted payload
        // would serve it to somebody who does hold the grant, purely because they arrived second.

        if ($withCosts) {
            return $charts;
        }

        foreach (self::COST_CHARTS as $key) {
            // Emptied to the shape the chart expects, so the component renders "nothing to show"
            // rather than failing on a missing key.
            $charts[$key] = is_array($charts[$key]) && ! array_is_list($charts[$key])
                ? array_map(fn () => null, $charts[$key])
                : [];
        }

        // The health score stays — knowing a project is in trouble is not commercially sensitive —
        // but the two indices behind it are, because CPI is the margin expressed as a ratio.
        $charts['health_by_project'] = array_map(
            fn (array $row) => ['spi' => null, 'cpi' => null] + $row,
            $charts['health_by_project'],
        );

        return $charts;
    }

    /** @return array<string, mixed> */
    private function allCharts(): array
    {
        return [
            'projects_by_stage'   => $this->projectsByStage(),
            'projects_by_status'  => $this->projectsByStatus(),
            'task_status_mix'     => $this->taskStatusMix(),
            'evidence_trend'      => $this->evidenceTrend(),
            'evidence_by_domain'  => $this->evidenceByDomain(),
            'progress_by_project' => $this->progressByProject(),
            'budget_vs_actual'    => $this->budgetVsActual(),
            'hours_trend'         => $this->hoursTrend(),
            'schedule_health'     => $this->scheduleHealth(),
            'overdue_by_project'  => $this->overdueByProject(),
            // Finance: where the money is going, not only how much is left.
            'cost_by_category'    => $this->costByCategory(),
            'cost_position'       => $this->costPosition(),
            'cost_trend'          => $this->costTrend(),
            // Resources: who is committed, and where that exceeds a working day.
            'allocation_by_person' => $this->allocationByPerson(),
            'workload_health'     => $this->workloadHealth(),
            'role_mix'            => $this->roleMix(),
            // Approvals: the queue nobody was watching before EPIC-090.
            'approval_backlog'    => $this->approvalBacklog(),
            // Earned value: whether the work done matches the money spent.
            'earned_value'        => $this->earnedValue(),
            'health_by_project'   => $this->healthByProject(),
            'cash_flow'           => $this->cashFlow(),
            'upcoming_milestones' => $this->upcomingMilestones(),
            'high_risks'          => $this->highRisks(),
            'client_satisfaction' => $this->clientSatisfaction(),
        ];
    }

    /** The portfolio's earned-value position. @return array<string, float|null> */
    public function earnedValue(): array
    {
        return app(EarnedValueService::class)->portfolio();
    }

    /**
     * A single health figure per project, and what pulled it down.
     *
     * Four things, weighted, because a project is rarely unhealthy for one reason and a score that
     * did not say *which* is a number nobody can act on:
     *
     *   schedule  SPI — is the work where the plan says it should be
     *   cost      CPI — is it costing what it should for the work done
     *   overdue   how much open work is past its date
     *   evidence  whether anybody is still recording anything on it
     *
     * The last one matters more than it looks. A project with no evidence for three weeks is not
     * a healthy project; it is one nobody is reporting on, and the other three figures are stale.
     *
     * @return list<array{code:string, name:string, score:int, spi:?float, cpi:?float, worst:string}>
     */
    public function healthByProject(int $limit = 10): array
    {
        $evm = app(EarnedValueService::class);
        $projects = Project::query()->active()->orderByDesc('updated_at')->limit($limit)->get();
        $ids = $projects->pluck('id');

        // Counted for every project in three queries rather than three per project. On a portfolio
        // of any size the loop version is the difference between a page and a timeout.
        $openCounts = Task::query()->real()->open()->whereIn('project_id', $ids)
            ->select('project_id', DB::raw('count(*) as total'))->groupBy('project_id')->pluck('total', 'project_id');

        $overdueCounts = Task::query()->real()->open()->whereIn('project_id', $ids)
            ->whereNotNull('planned_end')->where('planned_end', '<', now())
            ->select('project_id', DB::raw('count(*) as total'))->groupBy('project_id')->pluck('total', 'project_id');

        $lastEvidenceAt = EvidenceEvent::query()->whereIn('project_id', $ids)
            ->select('project_id', DB::raw('max(occurred_at) as latest'))->groupBy('project_id')->pluck('latest', 'project_id');

        $out = [];

        foreach ($projects as $project) {
            $row = $evm->forProject($project);

            $openCount = (int) ($openCounts[$project->id] ?? 0);
            $overdue = (int) ($overdueCounts[$project->id] ?? 0);

            $lastEvidence = $lastEvidenceAt[$project->id] ?? null;
            $daysQuiet = $lastEvidence ? CarbonImmutable::parse($lastEvidence)->diffInDays(now()) : null;

            // Each component is a 0–100 score. An index of 1.0 is 100; below that it falls off
            // proportionally, and above it is capped — being 30% under budget is not "130% healthy".
            $components = [
                'schedule' => $row['spi'] !== null ? (int) round(min(1.0, $row['spi']) * 100) : 100,
                'cost'     => $row['cpi'] !== null ? (int) round(min(1.0, $row['cpi']) * 100) : 100,
                'overdue'  => $openCount > 0 ? (int) round((1 - ($overdue / $openCount)) * 100) : 100,
                'evidence' => $daysQuiet === null ? 60 : (int) round(max(0, 1 - ($daysQuiet / 30)) * 100),
            ];

            $score = (int) round(
                $components['schedule'] * 0.3
                + $components['cost'] * 0.3
                + $components['overdue'] * 0.25
                + $components['evidence'] * 0.15
            );

            $out[] = [
                'code'  => $project->code,
                'name'  => $project->name,
                'score' => max(0, min(100, $score)),
                'spi'   => $row['spi'],
                'cpi'   => $row['cpi'],
                // Named, so the score is a starting point rather than a verdict.
                'worst' => array_search(min($components), $components, true) ?: 'schedule',
            ];
        }

        usort($out, fn ($a, $b) => $a['score'] <=> $b['score']);

        return $out;
    }

    /**
     * Money in and out by month, past and forecast.
     *
     * Out is what has been spent and what is committed but not yet paid — a commitment is a future
     * outflow with a date, which is precisely what a cash-flow view is for. In is paid customer
     * invoices where Finance is installed.
     *
     * @return list<array{month:string, out:float, committed:float, in:float}>
     */
    public function cashFlow(): array
    {
        $labour = TimeEntry::query()->approved()
            ->where('worked_on', '>=', $this->trendFloor())
            ->select(DB::raw("to_char(worked_on, 'YYYY-MM') as bucket"), DB::raw('sum(cost_amount) as total'))
            ->groupBy('bucket')->pluck('total', 'bucket');

        $paid = CostEntry::query()->paid()
            ->where('incurred_on', '>=', $this->trendFloor())
            ->select(DB::raw("to_char(incurred_on, 'YYYY-MM') as bucket"), DB::raw('sum(amount) as total'))
            ->groupBy('bucket')->pluck('total', 'bucket');

        $committed = CostEntry::query()->committed()
            ->where('incurred_on', '>=', $this->trendFloor())
            ->select(DB::raw("to_char(incurred_on, 'YYYY-MM') as bucket"), DB::raw('sum(amount) as total'))
            ->groupBy('bucket')->pluck('total', 'bucket');

        return array_map(fn (string $month) => [
            'month'     => $month,
            'out'       => round((float) ($labour[$month] ?? 0) + (float) ($paid[$month] ?? 0), 2),
            'committed' => round((float) ($committed[$month] ?? 0), 2),
            // Revenue needs Finance; without it this stays zero rather than being invented.
            'in'        => 0.0,
        ], $this->months());
    }

    /**
     * The next milestones due, across every active project.
     *
     * Overdue ones lead. A milestone that has already slipped is more urgent than one due on
     * Friday, and a list sorted purely by date buries it above the fold.
     *
     * @return list<array{project:string, name:string, due:?string, days:int, progress:float, overdue:bool}>
     */
    public function upcomingMilestones(int $limit = 8): array
    {
        return Task::query()
            ->real()
            ->open()
            ->where('is_milestone', true)
            ->whereNotNull('planned_end')
            ->where('planned_end', '<=', now()->addMonths(3))
            ->with('project:id,code,name')
            ->orderBy('planned_end')
            ->limit($limit)
            ->get()
            ->map(fn (Task $t) => [
                'project'  => $t->project?->code ?? '—',
                'name'     => $t->name,
                'due'      => $t->planned_end?->toDateString(),
                // Negative when it has already passed, which is what the screen colours on.
                'days'     => (int) now()->startOfDay()->diffInDays($t->planned_end->startOfDay(), false),
                'progress' => (float) $t->progress_pct,
                'overdue'  => $t->planned_end->isPast(),
            ])
            ->all();
    }

    /**
     * Non-labour cost by category — materials, plant, subcontractors.
     *
     * Approved and paid only. Draft and submitted costs are claims, and a chart that included them
     * would move every time somebody typed a number, before anyone had agreed to it.
     *
     * @return list<array{label:string, amount:float}>
     */
    public function costByCategory(): array
    {
        $rows = CostEntry::query()
            ->counted()
            ->select('category', DB::raw('sum(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'label'  => $row->category,
            'amount' => round((float) $row->total, 2),
        ])->all();
    }

    /**
     * The portfolio's money in one place: labour, non-labour, committed, and what is left.
     *
     * Labour and materials are split because they behave differently — labour is a run rate you can
     * slow down, materials are mostly committed the moment the order goes out — and a single
     * "actual" figure hides which of the two is the problem.
     *
     * @return array{budget:float, labour:float, other:float, committed:float, remaining:float}
     */
    public function costPosition(): array
    {
        $budget = (float) Project::query()->active()->sum('budget_amount');
        $labour = (float) TimeEntry::query()->approved()->sum('cost_amount');
        $paid = (float) CostEntry::query()->paid()->sum('amount');
        $committed = (float) CostEntry::query()->committed()->sum('amount');

        return [
            'budget'    => round($budget, 2),
            'labour'    => round($labour, 2),
            'other'     => round($paid, 2),
            'committed' => round($committed, 2),
            'remaining' => round($budget - $labour - $paid - $committed, 2),
        ];
    }

    /**
     * Labour against everything else, by month.
     *
     * @return list<array{month:string, labour:float, other:float}>
     */
    public function costTrend(): array
    {
        $labour = TimeEntry::query()
            ->approved()
            ->where('worked_on', '>=', $this->trendFloor())
            ->select(DB::raw("to_char(worked_on, 'YYYY-MM') as bucket"), DB::raw('sum(cost_amount) as total'))
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $other = CostEntry::query()
            ->counted()
            ->where('incurred_on', '>=', $this->trendFloor())
            ->select(DB::raw("to_char(incurred_on, 'YYYY-MM') as bucket"), DB::raw('sum(amount) as total'))
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return array_map(fn (string $month) => [
            'month'  => $month,
            'labour' => round((float) ($labour[$month] ?? 0), 2),
            'other'  => round((float) ($other[$month] ?? 0), 2),
        ], $this->months());
    }

    /**
     * Who is committed to how much, across open work.
     *
     * Summed across assignments rather than averaged: two 60% assignments in the same week is 120%
     * of a person, and an average would report 60% and hide it.
     *
     * @return list<array{name:string, allocation:int, tasks:int}>
     */
    public function allocationByPerson(int $limit = 10): array
    {
        $rows = TaskAssignment::query()
            ->join('pm_project_members', 'pm_project_members.id', '=', 'pm_task_assignments.member_id')
            ->join('pm_tasks', 'pm_tasks.id', '=', 'pm_task_assignments.task_id')
            ->whereNull('pm_tasks.deleted_at')
            ->whereNotIn('pm_tasks.status', ['done', 'cancelled'])
            ->select(
                'pm_project_members.display_name as name',
                DB::raw('sum(pm_task_assignments.allocation_pct) as allocation'),
                DB::raw('count(*) as tasks'),
            )
            ->groupBy('pm_project_members.display_name')
            ->orderByDesc('allocation')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'name'       => $row->name ?: '—',
            'allocation' => (int) $row->allocation,
            'tasks'      => (int) $row->tasks,
        ])->all();
    }

    /**
     * How many people are over-committed, and how many have nothing.
     *
     * Both ends matter: an over-committed foreman is a plan that will slip, and an idle one is
     * capacity nobody is using.
     *
     * @return array{over:int, full:int, light:int, idle:int}
     */
    public function workloadHealth(): array
    {
        $members = ProjectMember::query()->active()->count();

        $allocations = TaskAssignment::query()
            ->join('pm_tasks', 'pm_tasks.id', '=', 'pm_task_assignments.task_id')
            ->whereNull('pm_tasks.deleted_at')
            ->whereNotIn('pm_tasks.status', ['done', 'cancelled'])
            ->select('member_id', DB::raw('sum(allocation_pct) as allocation'))
            ->groupBy('member_id')
            ->pluck('allocation', 'member_id');

        $over = $allocations->filter(fn ($a) => $a > 100)->count();
        $full = $allocations->filter(fn ($a) => $a >= 80 && $a <= 100)->count();
        $light = $allocations->filter(fn ($a) => $a < 80)->count();

        return [
            'over'  => $over,
            'full'  => $full,
            'light' => $light,
            // Everybody with no open assignment at all.
            'idle'  => max(0, $members - $allocations->count()),
        ];
    }

    /** The shape of the team across the portfolio. @return list<array{label:string, count:int}> */
    public function roleMix(): array
    {
        $counts = ProjectMember::query()
            ->active()
            ->select('role_key', DB::raw('count(*) as total'))
            ->groupBy('role_key')
            ->pluck('total', 'role_key');

        return $counts->map(fn ($total, $role) => ['label' => $role, 'count' => (int) $total])->values()->all();
    }

    /**
     * What is sitting in the approvals queue, by how long it has waited.
     *
     * Bucketed rather than averaged: one request stuck for three weeks is the thing worth seeing,
     * and a mean waiting time buries it under forty that cleared the same day.
     *
     * @return array{today:int, this_week:int, over_a_week:int, over_a_month:int}
     */
    public function approvalBacklog(): array
    {
        $pending = ApprovalRequest::query()->pending();

        return [
            'today'        => (clone $pending)->where('created_at', '>=', now()->subDay())->count(),
            'this_week'    => (clone $pending)->whereBetween('created_at', [now()->subWeek(), now()->subDay()])->count(),
            'over_a_week'  => (clone $pending)->whereBetween('created_at', [now()->subMonth(), now()->subWeek()])->count(),
            'over_a_month' => (clone $pending)->where('created_at', '<', now()->subMonth())->count(),
        ];
    }

    /** @return list<array{label:string, count:int}> */
    public function projectsByStage(): array
    {
        return $this->labelled(
            Project::query()->select('stage', DB::raw('count(*) as total'))->groupBy('stage')->pluck('total', 'stage'),
            Project::STAGES
        );
    }

    /** @return list<array{label:string, count:int}> */
    public function projectsByStatus(): array
    {
        return $this->labelled(
            Project::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            []
        );
    }

    /** @return list<array{label:string, count:int}> */
    public function taskStatusMix(): array
    {
        $counts = Task::query()
            ->real()
            // Archived work is shelved, not a state the work is in. A slice for it would grow with
            // every project's housekeeping and make the mix read as though a third of the job were
            // in some sixth condition nobody is working on.
            ->onBoard()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // Every status appears, including the empty ones: a donut that silently omits "blocked"
        // reads as a project with nothing blocked.
        return array_map(
            fn (string $status) => ['label' => $status, 'count' => (int) ($counts[$status] ?? 0)],
            Task::BOARD_STATUSES,
        );
    }

    /**
     * Evidence recorded per month — the pulse of the field loop.
     *
     * @return list<array{month:string, count:int}>
     */
    public function evidenceTrend(): array
    {
        $rows = EvidenceEvent::query()
            ->where('occurred_at', '>=', $this->trendFloor())
            ->select(DB::raw("to_char(occurred_at, 'YYYY-MM') as bucket"), DB::raw('count(*) as total'))
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->fillMonths($rows);
    }

    /** @return list<array{label:string, count:int}> */
    public function evidenceByDomain(): array
    {
        $rows = EvidenceEvent::query()
            ->live()
            ->where('occurred_at', '>=', $this->trendFloor())
            ->select('event_type', DB::raw('count(*) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        $byDomain = [];

        foreach ($rows as $type => $total) {
            $domain = \App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy::domainOf($type) ?? 'other';
            $byDomain[$domain] = ($byDomain[$domain] ?? 0) + (int) $total;
        }

        arsort($byDomain);

        return array_map(fn ($k, $v) => ['label' => $k, 'count' => $v], array_keys($byDomain), $byDomain);
    }

    /**
     * Progress per active project, from the derivation cache rather than recomputed.
     *
     * @return list<array{code:string, name:string, progress:float}>
     */
    public function progressByProject(int $limit = 10): array
    {
        return Project::query()
            ->active()
            ->withAvg(['tasks as progress' => fn ($q) => $q->real()], 'progress_pct')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Project $p) => [
                'code'     => $p->code,
                'name'     => $p->name,
                'progress' => round((float) ($p->progress ?? 0), 1),
            ])
            ->all();
    }

    /**
     * Budget against what has been spent, per project.
     *
     * Committed cost is kept out: this chart answers "how much of the budget is gone", and folding
     * in money that has not left the account yet would make every project look worse than it is
     * without saying why. The Delivery page separates the three properly.
     *
     * @return list<array{code:string, budget:float, actual:float}>
     */
    public function budgetVsActual(int $limit = 8): array
    {
        $projects = Project::query()
            ->active()
            ->whereNotNull('budget_amount')
            ->where('budget_amount', '>', 0)
            ->orderByDesc('budget_amount')
            ->limit($limit)
            ->get(['id', 'code', 'budget_amount']);

        if ($projects->isEmpty()) {
            return [];
        }

        $labour = TimeEntry::query()
            ->approved()
            ->whereIn('project_id', $projects->pluck('id'))
            ->select('project_id', DB::raw('sum(cost_amount) as total'))
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        $derived = DerivedValue::query()
            ->where('subject_type', 'task')
            ->where('metric', 'actual_cost')
            ->whereIn('subject_id', Task::whereIn('project_id', $projects->pluck('id'))->select('id'))
            ->join('pm_tasks', 'pm_tasks.id', '=', 'pm_derived_values.subject_id')
            ->select('pm_tasks.project_id', DB::raw('sum(pm_derived_values.value_numeric) as total'))
            ->groupBy('pm_tasks.project_id')
            ->pluck('total', 'project_id');

        return $projects->map(fn (Project $p) => [
            'code'   => $p->code,
            'budget' => round((float) $p->budget_amount, 2),
            'actual' => round((float) ($labour[$p->id] ?? 0) + (float) ($derived[$p->id] ?? 0), 2),
        ])->all();
    }

    /**
     * Approved against unapproved hours per month.
     *
     * Split because they are different claims: approved hours are a supervisor standing behind
     * them, everything else is somebody's account of their own day (PM-ADR-010).
     *
     * @return list<array{month:string, approved:float, pending:float}>
     */
    public function hoursTrend(): array
    {
        $rows = TimeEntry::query()
            ->where('worked_on', '>=', $this->trendFloor())
            ->select(
                DB::raw("to_char(worked_on, 'YYYY-MM') as bucket"),
                DB::raw("sum(case when status = 'approved' then hours else 0 end) as approved"),
                DB::raw("sum(case when status <> 'approved' then hours else 0 end) as pending"),
            )
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $out = [];

        foreach ($this->months() as $month) {
            $row = $rows->get($month);

            $out[] = [
                'month'    => $month,
                'approved' => round((float) ($row->approved ?? 0), 2),
                'pending'  => round((float) ($row->pending ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * How the portfolio's tasks stand against their dates.
     *
     * @return array{on_track:int, at_risk:int, overdue:int, unscheduled:int}
     */
    public function scheduleHealth(): array
    {
        $open = Task::query()->real()->open();

        return [
            'overdue'     => (clone $open)->whereNotNull('planned_end')->where('planned_end', '<', now())->count(),
            // Critical and due inside a week: the work where a lost day is a lost day off the end.
            'at_risk'     => (clone $open)->where('is_critical', true)
                ->whereNotNull('planned_end')
                ->whereBetween('planned_end', [now(), now()->addWeek()])
                ->count(),
            'on_track'    => (clone $open)->whereNotNull('planned_end')->where('planned_end', '>=', now())->count(),
            // Never scheduled: not late, but nobody has said when it happens either.
            'unscheduled' => (clone $open)->whereNull('planned_end')->count(),
        ];
    }

    /** @return list<array{code:string, name:string, overdue:int}> */
    public function overdueByProject(int $limit = 8): array
    {
        // Filtered after the fetch, not with HAVING: `overdue` is a sub-select alias, and Postgres
        // will not resolve one in a HAVING clause (there is no GROUP BY for it to belong to).
        // ORDER BY does accept the alias, so the ordering and the limit still happen in the database.
        return Project::query()
            ->active()
            ->withCount(['tasks as overdue' => fn ($q) => $q->real()->open()
                ->whereNotNull('planned_end')->where('planned_end', '<', now())])
            ->orderByDesc('overdue')
            ->limit($limit)
            ->get()
            ->filter(fn (Project $p) => (int) $p->overdue > 0)
            ->map(fn (Project $p) => [
                'code'    => $p->code,
                'name'    => $p->name,
                'overdue' => (int) $p->overdue,
            ])
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function trendFloor(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMonths(self::TREND_MONTHS - 1)->startOfMonth();
    }

    /** @return list<string> */
    private function months(): array
    {
        $months = [];

        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $months[] = CarbonImmutable::now()->subMonths($i)->format('Y-m');
        }

        return $months;
    }

    /**
     * Months with no rows still appear, at zero.
     *
     * A trend line that simply skips a quiet month draws a straight line across it, which reads as
     * steady activity through a period when nothing happened at all.
     *
     * @return list<array{month:string, count:int}>
     */
    private function fillMonths(\Illuminate\Support\Collection $counts): array
    {
        return array_map(
            fn (string $month) => ['month' => $month, 'count' => (int) ($counts[$month] ?? 0)],
            $this->months(),
        );
    }

    /**
     * @param  list<string>  $expected  values that must appear even at zero
     * @return list<array{label:string, count:int}>
     */
    private function labelled(\Illuminate\Support\Collection $counts, array $expected): array
    {
        $keys = $expected !== [] ? $expected : $counts->keys()->all();

        return array_map(
            fn (string $key) => ['label' => $key, 'count' => (int) ($counts[$key] ?? 0)],
            $keys,
        );
    }

    /**
     * The risks a steering committee should be looking at (§1).
     *
     * Live only — a realised or closed risk belongs in the history, not on a dashboard of what is
     * still ahead.
     *
     * @return list<array<string,mixed>>
     */
    private function highRisks(): array
    {
        return \App\Modules\Operations\ProjectManagement\Models\Risk::query()
            ->live()
            ->where('score', '>=', 15)
            ->with('project:id,code')
            ->orderByDesc('score')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'project' => $r->project?->code,
                'code'    => $r->code,
                'title'   => $r->title,
                'score'   => $r->score,
                // Shown so a risk with a high score and nothing being done about it is visible as
                // exactly that, rather than as a number somebody assumes is under control.
                'has_mitigation' => filled($r->mitigation),
            ])->all();
    }

    /**
     * The average of what clients actually said, this quarter.
     *
     * Null when nobody has rated anything — which is the honest answer, and better than a 0 that
     * reads as "every client is unhappy" or a 100% that reads as "everything is fine".
     *
     * @return array{score:?float, responses:int}
     */
    private function clientSatisfaction(): array
    {
        $ratings = \App\Modules\Operations\ProjectManagement\Models\ClientRating::query()
            ->where('period_month', '>=', now()->subMonths(3)->startOfMonth())
            ->pluck('score');

        return [
            'score'     => $ratings->isEmpty() ? null : round((float) $ratings->avg(), 1),
            'responses' => $ratings->count(),
        ];
    }
}
