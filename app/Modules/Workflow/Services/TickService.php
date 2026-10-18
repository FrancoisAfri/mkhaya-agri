<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Workflow\Models\WorkflowActionRun;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTaskAssignment;
use App\Modules\Workflow\Models\WorkflowTimer;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens later (PRS §10, §17, §19), run every minute by workflow:tick.
 *
 * Each due row is claimed (status flipped in one UPDATE), then handled under its own tenant
 * context and instance lock, so two overlapping ticks cannot fire the same timer twice and a
 * failure on one row never stops the others. Nothing here relies on a queue worker.
 */
class TickService
{
    public function __construct(
        private readonly WorkflowRunner $runner,
        private readonly WorkflowEvents $events,
        private readonly WorkflowAudit $audit,
        private readonly Notifier $notifier,
        private readonly AssigneeResolver $resolver,
        private readonly TenantContext $tenants,
    ) {
    }

    /** @return array<string, int> */
    public function run(?int $companyId = null): array
    {
        $stats = ['timers' => 0, 'actions' => 0, 'scheduled' => 0, 'events' => 0, 'errors' => 0];

        $timers = WorkflowTimer::query()->withoutGlobalScopes()->where('status', 'pending')->where('fire_at', '<=', now())
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderBy('fire_at')->limit(1000)->get();
        foreach ($timers as $timer) {
            if (! WorkflowTimer::query()->withoutGlobalScopes()->whereKey($timer->id)->where('status', 'pending')->update(['status' => 'fired', 'fired_at' => now()])) {
                continue; // another tick took it
            }
            $this->guard($timer->company_id, function () use ($timer) {
                $this->fire($timer);
            }, $stats, 'timers');
        }

        $actions = WorkflowActionRun::query()->withoutGlobalScopes()->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderBy('next_attempt_at')->limit(500)->get();
        foreach ($actions as $action) {
            $this->guard($action->company_id, function () use ($action) {
                DB::transaction(function () use ($action) {
                    $instance = WorkflowInstance::query()->lockForUpdate()->find($action->instance_id);
                    $action = WorkflowActionRun::query()->lockForUpdate()->find($action->id);
                    if ($instance && $action && $action->status === 'pending' && $action->next_attempt_at?->lte(now())) {
                        $this->runner->retryAction($instance, $action);
                    }
                });
            }, $stats, 'actions');
        }

        $this->startScheduled($companyId, $stats);
        $stats['events'] = $this->events->deliver()['delivered'];

        return $stats;
    }

    private function fire(WorkflowTimer $timer): void
    {
        DB::transaction(function () use ($timer) {
            $instance = WorkflowInstance::query()->lockForUpdate()->find($timer->instance_id);
            if (! $instance) {
                return;
            }
            $task = $timer->task_id ? WorkflowTask::query()->lockForUpdate()->find($timer->task_id) : null;

            switch ($timer->kind) {
                case 'delay':
                    if ($instance->status === 'running') {
                        $this->runner->delayElapsed($instance, $timer);
                    }
                    break;

                case 'reminder':
                    $this->notifier->send(array_map('intval', $timer->payload['to'] ?? []), 'reminder', "Reminder: {$instance->reference}", (string) ($timer->payload['message'] ?? ''),
                        url('/workflow/instances/' . $instance->uuid), $instance, 'reminder:' . $timer->id);
                    break;

                case 'sla_warning':
                    if ($task?->isOpen() && $task->sla_state === 'on_track') {
                        $task->forceFill(['sla_state' => 'warning', 'warned_at' => now()])->save();
                        $this->notifier->send(array_filter([$task->assignee_id]) ?: $task->candidates, 'sla_warning', "Due soon: {$task->name}",
                            "{$instance->reference} is due " . $task->due_at->setTimezone('Africa/Johannesburg')->format('j M H:i') . '.', url('/workflow/tasks/' . $task->uuid), $instance, 'warn:' . $task->id);
                        $this->events->publish('workflow.sla.warning', $this->runner->eventPayload($instance) + ['task' => $task->uuid]);
                        $this->audit->as('service', 'workflow:tick', fn () => $this->audit->record('sla.warning', $task, ['instance' => $instance]));
                    }
                    break;

                case 'sla_breach':
                    // §10, WF-010: the escalation runs once — the timer fires once and the task is marked.
                    if ($task?->isOpen() && $task->sla_state !== 'breached' && $task->sla_state !== 'paused') {
                        $task->forceFill(['sla_state' => 'breached', 'breached_at' => now()])->save();
                        $this->escalate($instance, $task, (string) ($timer->payload['escalation'] ?? 'notify_manager'));
                        $this->events->publish('workflow.sla.breached', $this->runner->eventPayload($instance) + ['task' => $task->uuid]);
                    }
                    break;
            }
        });
    }

    private function escalate(WorkflowInstance $instance, WorkflowTask $task, string $how): void
    {
        $assignee = $task->assignee_id;
        $manager = $this->resolver->managerOf($assignee);
        $owner = $instance->definition?->process_owner_id;
        $url = url('/workflow/tasks/' . $task->uuid);
        $did = $how;
        switch ($how) {
            case 'notify_assignee':
                $this->notifier->send(array_filter([$assignee]) ?: $task->candidates, 'sla_breached', "Overdue: {$task->name}", "{$instance->reference} is past its deadline.", $url, $instance, 'breach:' . $task->id);
                break;
            case 'reassign_manager':
                if ($manager && $manager !== $instance->requester_id) {
                    WorkflowTaskAssignment::create(['company_id' => $task->company_id, 'task_id' => $task->id, 'action' => 'escalated', 'from_user_id' => $assignee, 'to_user_id' => $manager, 'reason' => 'SLA breached']);
                    $task->forceFill(['assignee_id' => $manager, 'candidates' => []])->save();
                    $this->notifier->send([$manager], 'sla_breached', "Escalated to you: {$task->name}", "{$instance->reference} was overdue and has been reassigned to you.", $url, $instance, 'breach:' . $task->id);
                    break;
                }
                $did = 'notify_manager (no manager to reassign to)';
                // fall through to notifying
            case 'notify_manager':
                $this->notifier->send(array_filter([$manager, $assignee]), 'sla_breached', "Overdue: {$task->name}", "{$instance->reference} assigned to your team is past its deadline.", $url, $instance, 'breach:' . $task->id);
                break;
            case 'notify_owner':
                $this->notifier->send(array_filter([$owner, $assignee]), 'sla_breached', "Overdue: {$task->name}", "{$instance->reference} is past its deadline.", $url, $instance, 'breach:' . $task->id);
                break;
            case 'raise_priority':
                $task->forceFill(['priority' => 'high'])->save();
                $instance->forceFill(['priority' => 'high'])->save();
                $this->notifier->send(array_filter([$assignee]), 'sla_breached', "Overdue (now high priority): {$task->name}", $instance->reference, $url, $instance, 'breach:' . $task->id);
                break;
            case 'exception':
                $instance->forceFill(['error' => "SLA breached on \"{$task->name}\"."])->save();
                $this->notifier->send(array_filter([$owner]), 'exception', "SLA breached: {$task->name}", $instance->reference, $url, $instance, 'breach:' . $task->id);
                break;
        }
        $this->audit->as('service', 'workflow:tick', fn () => $this->audit->record('sla.breached', $task, ['instance' => $instance, 'context' => ['escalation' => $did, 'assignee' => $assignee, 'manager' => $manager]]));
    }

    /** §5 scheduled and recurring triggers: one request per period, de-duplicated by the period. */
    private function startScheduled(?int $companyId, array &$stats): void
    {
        $definitions = WorkflowDefinition::query()->withoutGlobalScopes()->where('status', 'published')->where('trigger_type', 'scheduled')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->get();
        foreach ($definitions as $d) {
            $cfg = $d->trigger_config ?? [];
            [$due, $period] = $this->schedulePeriod($cfg);
            if (! $due) {
                continue;
            }
            $this->guard($d->company_id, function () use ($d, $period) {
                $instance = $this->audit->as('service', 'workflow:tick', fn () => $this->runner->start($d, [], $d->process_owner_id, [], "sched:{$d->id}:{$period}", null, "{$d->name} — {$period}"));
                if ($instance->wasRecentlyCreated) {
                    return true;
                }
            }, $stats, 'scheduled');
        }
    }

    /** @return array{0:bool, 1:string} whether this period's run is due, and the period's key */
    public function schedulePeriod(array $cfg, ?\DateTimeInterface $at = null): array
    {
        $now = \Carbon\CarbonImmutable::instance($at ?? now())->setTimezone('Africa/Johannesburg');
        [$h, $m] = array_map('intval', explode(':', $cfg['time'] ?? '06:00') + [1 => 0]);
        $todayAt = $now->setTime($h, $m);

        return match ($cfg['frequency'] ?? 'daily') {
            'weekly' => [$now->dayOfWeekIso === (int) ($cfg['weekday'] ?? 1) && $now >= $todayAt, $now->format('o-\WW')],
            'monthly' => [$now->day === min((int) ($cfg['day'] ?? 1), $now->daysInMonth) && $now >= $todayAt, $now->format('Y-m')],
            default => [$now >= $todayAt, $now->toDateString()],
        };
    }

    private function guard(?int $companyId, callable $fn, array &$stats, string $bucket): void
    {
        try {
            $this->tenants->run($companyId, $fn);
            $stats[$bucket]++;
        } catch (\Throwable $e) {
            report($e);
            $stats['errors']++;
        }
    }
}
