<?php

namespace App\Modules\Workflow\Listeners;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowTaskAssignment;
use App\Modules\Workflow\Services\AssigneeResolver;
use App\Modules\Workflow\Services\Notifier;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Support\Facades\DB;

/**
 * PRS §3, WF-016: when a person is disabled (offboarding, SCIM, an administrator) or deleted, their
 * open tasks go where the company's policy says — their line manager, the process owner, or the
 * exception queue — and their delegations end. Watches the User model itself, so every path that
 * disables someone is covered without each one having to remember to tell the engine.
 */
class ReassignOnDeactivation
{
    public function __construct(
        private readonly AssigneeResolver $resolver,
        private readonly WorkflowSettings $settings,
        private readonly WorkflowAudit $audit,
        private readonly Notifier $notifier,
    ) {
    }

    public function handle(User $user): void
    {
        if (! $user->company_id) {
            return;
        }
        app(TenantContext::class)->run($user->company_id, fn () => DB::transaction(fn () => $this->reassign($user)));
    }

    private function reassign(User $user): void
    {
        $policy = $this->settings->get('deactivation_policy', $user->company_id);
        $tasks = WorkflowTask::query()->where('status', 'open')->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhereJsonContains('candidates', $user->id))
            ->lockForUpdate()->get();

        foreach ($tasks as $task) {
            $instance = WorkflowInstance::query()->with('definition')->find($task->instance_id);
            if ($task->assignee_id !== $user->id) {
                // Only a candidate in a queue: drop them from it.
                $task->candidates = array_values(array_diff(array_map('intval', $task->candidates ?? []), [$user->id]));
                $task->save();

                continue;
            }
            $to = match ($policy) {
                'process_owner' => $instance?->definition?->process_owner_id,
                'exception' => null,
                default => $this->resolver->managerOf($user->id) ?? $instance?->definition?->process_owner_id,
            };
            $to = $to && $this->resolver->active([(int) $to], $user->company_id) && $to !== $instance?->requester_id ? (int) $to : null;
            $updates = $to ? ['assignee_id' => $to, 'candidates' => []]
                : ['assignee_id' => null, 'type' => 'exception', 'name' => 'Needs attention: ' . $task->name, 'comment' => 'The assignee was deactivated and no replacement could be found.',
                    'candidates' => $this->resolver->usersWithRole('Workflow Administrator', $user->company_id)];
            $task->forceFill($updates)->save();
            WorkflowTaskAssignment::create(['company_id' => $task->company_id, 'task_id' => $task->id, 'action' => 'reassigned', 'from_user_id' => $user->id, 'to_user_id' => $to, 'reason' => 'Assignee deactivated']);
            $this->audit->as('service', 'deactivation', fn () => $this->audit->record('task.reassigned', $task, ['instance' => $instance, 'reason' => 'Assignee deactivated',
                'before' => ['assignee' => $user->id], 'after' => ['assignee' => $to, 'policy' => $policy]]));
            $this->notifier->send($to ? [$to] : $updates['candidates'], 'assigned', ($to ? 'Task handed to you: ' : 'Needs attention: ') . $task->name,
                ($instance?->reference ?? '') . ' — the previous assignee is no longer active.', url('/workflow/tasks/' . $task->uuid), $instance, 'deact:' . $task->id);
        }

        WorkflowDelegation::query()->where('status', 'active')->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
    }
}
