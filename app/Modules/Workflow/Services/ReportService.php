<?php

namespace App\Modules\Workflow\Services;

use App\Core\User\Models\User;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational and executive figures (PRS §14). Everything is computed from the instances the viewer
 * may see, in grouped queries. Time with people (task open → decided) is reported apart from time
 * waiting on timers and automated steps, so a slow approver and a slow integration are not confused.
 */
class ReportService
{
    public function __construct(private readonly WorkflowAccess $access, private readonly Presenter $present)
    {
    }

    /** @param array{from?:string, to?:string, workflow?:string, department?:int, priority?:string} $f */
    public function instances(User $user, array $f): Builder
    {
        $from = CarbonImmutable::parse($f['from'] ?? now()->subDays(90)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($f['to'] ?? now()->toDateString())->endOfDay();

        return $this->access->scopeVisible(WorkflowInstance::query(), $user, asManager: true)
            ->whereBetween('started_at', [$from, $to])
            ->when($f['workflow'] ?? null, fn ($q, $u) => $q->whereHas('definition', fn ($d) => $d->where('uuid', $u)))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($f['department'] ?? null, fn ($q, $d) => $q->whereRaw("(data->'requester'->>'department_id')::bigint = ?", [(int) $d]));
    }

    public function summary(User $user, array $f): array
    {
        $ids = $this->instances($user, $f)->select('id');
        $byStatus = WorkflowInstance::query()->whereIn('id', $ids)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $durations = WorkflowInstance::query()->whereIn('id', $ids)->whereNotNull('completed_at')->whereIn('status', ['completed', 'rejected'])
            ->selectRaw('avg(extract(epoch from completed_at - started_at))/3600 as avg_h, percentile_cont(0.5) within group (order by extract(epoch from completed_at - started_at))/3600 as median_h')->first();
        $tasks = WorkflowTask::query()->whereIn('instance_id', $ids);
        $slaBase = (clone $tasks)->whereNotNull('due_at')->where('status', 'completed');
        $slaTotal = (clone $slaBase)->count();
        $slaMet = (clone $slaBase)->whereColumn('completed_at', '<=', 'due_at')->count();
        $people = (clone $tasks)->whereNotNull('completed_at')->where('status', 'completed')->selectRaw('sum(extract(epoch from completed_at - created_at))/3600 as h')->value('h');
        $elapsed = WorkflowInstance::query()->whereIn('id', $ids)->whereNotNull('completed_at')->selectRaw('sum(extract(epoch from completed_at - started_at))/3600 as h')->value('h');
        $finished = (int) (($byStatus['completed'] ?? 0) + ($byStatus['rejected'] ?? 0));
        $reworked = WorkflowTask::query()->whereIn('instance_id', $ids)->where('decision', 'returned')->distinct('instance_id')->count('instance_id');
        $started = (int) $byStatus->sum();

        return [
            'started' => $started, 'running' => (int) ($byStatus['running'] ?? 0), 'completed' => (int) ($byStatus['completed'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0), 'cancelled' => (int) ($byStatus['cancelled'] ?? 0), 'failed' => (int) ($byStatus['failed'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'awaiting_approval' => (clone $tasks)->where('status', 'open')->where('type', 'approval')->count(),
            'overdue' => (clone $tasks)->where('status', 'open')->where('due_at', '<', now())->count(),
            'breaches' => (clone $tasks)->whereNotNull('breached_at')->count(),
            'needs_intervention' => WorkflowInstance::query()->whereIn('id', $ids)->where('status', 'running')
                ->where(fn ($q) => $q->whereNotNull('error')->orWhereHas('tasks', fn ($t) => $t->where('status', 'open')->where('type', 'exception')))->count(),
            'avg_hours' => $durations?->avg_h !== null ? round((float) $durations->avg_h, 3) : null,
            'median_hours' => $durations?->median_h !== null ? round((float) $durations->median_h, 3) : null,
            'sla_compliance' => $slaTotal ? round(100 * $slaMet / $slaTotal, 1) : null, 'sla_measured' => $slaTotal,
            'rejection_rate' => $finished ? round(100 * ($byStatus['rejected'] ?? 0) / $finished, 1) : null,
            'rework_rate' => $finished ? round(100 * $reworked / max(1, $finished), 1) : null,
            'failure_rate' => $started ? round(100 * ($byStatus['failed'] ?? 0) / $started, 1) : null,
            'hours_with_people' => $people !== null ? round((float) $people, 3) : 0, 'hours_elapsed' => $elapsed !== null ? round((float) $elapsed, 3) : 0,
        ];
    }

    /** Average wait per step: where requests spend their time (bottlenecks). */
    public function bottlenecks(User $user, array $f, int $limit = 15): array
    {
        $ids = $this->instances($user, $f)->select('id');

        return DB::table('workflow_tasks as t')->join('workflow_instances as i', 'i.id', '=', 't.instance_id')->join('workflow_definitions as d', 'd.id', '=', 'i.definition_id')
            ->whereIn('t.instance_id', $ids)->whereNotNull('t.completed_at')->where('t.status', 'completed')
            ->groupBy('d.name', 't.name')
            ->selectRaw("d.name as workflow, t.name as step, count(*) as tasks, round((avg(extract(epoch from t.completed_at - t.created_at))/3600)::numeric, 3) as avg_hours,
                round((percentile_cont(0.5) within group (order by extract(epoch from t.completed_at - t.created_at))/3600)::numeric, 3) as median_hours,
                sum(case when t.breached_at is not null then 1 else 0 end) as breaches, sum(case when t.decision = 'returned' then 1 else 0 end) as returned")
            ->orderByDesc('avg_hours')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    public function byWorkflow(User $user, array $f): array
    {
        $ids = $this->instances($user, $f)->select('id');

        return DB::table('workflow_instances as i')->join('workflow_definitions as d', 'd.id', '=', 'i.definition_id')->whereIn('i.id', $ids)
            ->groupBy('d.name', 'd.category')
            ->selectRaw("d.name as workflow, d.category, count(*) as started, sum(case when i.status = 'completed' then 1 else 0 end) as completed,
                sum(case when i.status = 'rejected' then 1 else 0 end) as rejected, sum(case when i.status = 'running' then 1 else 0 end) as running,
                round((avg(case when i.completed_at is not null then extract(epoch from i.completed_at - i.started_at) end)/3600)::numeric, 3) as avg_hours")
            ->orderByDesc('started')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function workload(User $user, array $f): array
    {
        $ids = $this->instances($user, array_merge($f, ['from' => '2000-01-01']))->where('status', 'running')->select('id');

        return WorkflowTask::query()->whereIn('instance_id', $ids)->where('status', 'open')
            ->selectRaw("assignee_id, count(*) n, sum(case when due_at < now() then 1 else 0 end) overdue, sum(case when priority = 'high' then 1 else 0 end) high")
            ->groupBy('assignee_id')->orderByDesc('n')->limit(20)->get()
            ->map(fn ($r) => ['person' => $r->assignee_id ? $this->present->name($r->assignee_id) : 'Unclaimed (queues)', 'open' => (int) $r->n, 'overdue' => (int) $r->overdue, 'high' => (int) $r->high])->all();
    }

    public function byDepartment(User $user, array $f): array
    {
        $ids = $this->instances($user, $f)->select('id');
        $rows = DB::table('workflow_tasks as t')->join('workflow_instances as i', 'i.id', '=', 't.instance_id')->whereIn('i.id', $ids)
            ->where('t.status', 'open')->where('t.type', 'approval')
            ->selectRaw("(i.data->'requester'->>'department_id') as dept, count(*) n")->groupBy('dept')->get();
        $names = DB::table('company_units')->whereIn('id', $rows->pluck('dept')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => ['department' => $r->dept ? ($names[$r->dept] ?? 'Unit ' . $r->dept) : 'No department', 'awaiting' => (int) $r->n])->sortByDesc('awaiting')->values()->all();
    }

    /** Weekly started / completed, for the trend chart. */
    public function trend(User $user, array $f): array
    {
        $ids = $this->instances($user, $f)->select('id');
        $started = WorkflowInstance::query()->whereIn('id', $ids)->selectRaw("to_char(date_trunc('week', started_at), 'YYYY-MM-DD') w, count(*) n")->groupBy('w')->pluck('n', 'w');
        $done = WorkflowInstance::query()->whereIn('id', $ids)->whereNotNull('completed_at')->selectRaw("to_char(date_trunc('week', completed_at), 'YYYY-MM-DD') w, count(*) n")->groupBy('w')->pluck('n', 'w');
        $weeks = $started->keys()->merge($done->keys())->unique()->sort()->values();

        return ['weeks' => $weeks->all(), 'started' => $weeks->map(fn ($w) => (int) ($started[$w] ?? 0))->all(), 'completed' => $weeks->map(fn ($w) => (int) ($done[$w] ?? 0))->all()];
    }

    public function byPriority(User $user, array $f): array
    {
        $ids = $this->instances($user, $f)->select('id');

        return WorkflowTask::query()->whereIn('instance_id', $ids)->where('status', 'open')->selectRaw('priority, count(*) n')->groupBy('priority')->pluck('n', 'priority')->all();
    }
}
