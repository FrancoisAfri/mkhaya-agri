<?php

namespace App\Modules\Operations\ProjectManagement\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;

/**
 * Authorisation for tasks and the schedule, decided by PermissionEvaluator against the task's own
 * project — so a Team Member on one job cannot touch tasks on another.
 *
 * `schedule` is separated from `update` deliberately: editing a task's name or notes is routine,
 * but rescheduling shifts the critical path and therefore every downstream commitment. The two are
 * different grants because they are different acts.
 */
class TaskPolicy
{
    public function __construct(private readonly PermissionEvaluator $evaluator)
    {
    }

    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_platform_admin || $user->is_master_admin) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['administrator', 'super admin'])) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->evaluator->canAnywhere($user, RoleCatalog::VIEW_TASKS);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->evaluator->can($user, RoleCatalog::VIEW_TASKS, $task->project);
    }

    public function create(User $user): bool
    {
        return $this->evaluator->can($user, RoleCatalog::CREATE_TASKS);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->evaluator->can($user, RoleCatalog::UPDATE_TASKS, $task->project);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->evaluator->can($user, RoleCatalog::DELETE_TASKS, $task->project);
    }

    /**
     * Taking part: commenting, ticking a checklist item, watching, attaching a photo.
     *
     * Deliberately the same bar as seeing the task. A person who can read the work but cannot say
     * "the ties are short" on it will say it somewhere the project never sees, and the module loses
     * the one record that mattered.
     */
    public function collaborate(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    /** Re-running the CPM engine or pinning dates — moves everyone's dates, not just this task's. */
    public function schedule(User $user, ?Task $task = null): bool
    {
        return $this->evaluator->can($user, RoleCatalog::MANAGE_SCHEDULE, $task?->project);
    }
}
