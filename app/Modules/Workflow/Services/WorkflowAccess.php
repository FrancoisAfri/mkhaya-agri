<?php

namespace App\Modules\Workflow\Services;

use App\Core\User\Models\User;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and act on what (PRS §3, §18).
 *
 *   act on a task   — its assignee; a person in its queue once they claim it; or someone the
 *                     assignee has delegated to, for that workflow's category (on the assignee's behalf).
 *   see a request   — its requester and everyone who has had a task on it; process managers and
 *                     auditors for ordinary workflows. A confidential workflow is visible only to the
 *                     named members and its participants, whatever else a person may do.
 * Tenant isolation is underneath all of it (row-level security and the company scope).
 */
class WorkflowAccess
{
    /** The people a user may currently act for (themself plus active delegators), by category. */
    public function actingFor(User $user, ?string $category = null): array
    {
        $ids = [$user->id];
        $delegations = WorkflowDelegation::query()->activeAt()->where('to_user_id', $user->id)->get();
        foreach ($delegations as $d) {
            if ($category === null || empty($d->categories) || in_array($category, $d->categories, true)) {
                $ids[] = (int) $d->from_user_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Whether $user may decide $task, and on whose behalf (null = their own). */
    public function canAct(User $user, WorkflowTask $task): array
    {
        if (! $task->isOpen()) {
            return [false, null];
        }
        if ($task->assignee_id === $user->id) {
            return [true, null];
        }
        if ($task->assignee_id === null) {
            return [false, null]; // a queue task is claimed first
        }
        $category = $task->instance?->definition?->category;
        if (in_array($task->assignee_id, $this->actingFor($user, $category), true)) {
            return [true, (int) $task->assignee_id];
        }

        return [false, null];
    }

    public function canClaim(User $user, WorkflowTask $task): bool
    {
        return $task->isOpen() && $task->assignee_id === null && in_array($user->id, array_map('intval', $task->candidates ?? []), true);
    }

    public function isParticipant(User $user, WorkflowInstance $instance): bool
    {
        if ($instance->requester_id === $user->id) {
            return true;
        }
        $mine = $this->actingFor($user, $instance->definition?->category);

        return WorkflowTask::query()->where('instance_id', $instance->id)->where(function ($q) use ($mine, $user) {
            $q->whereIn('assignee_id', $mine)->orWhere('decided_by', $user->id)->orWhere('original_assignee_id', $user->id)
                ->orWhereJsonContains('candidates', $user->id);
        })->exists();
    }

    public function canView(User $user, WorkflowInstance $instance): bool
    {
        if ($this->isParticipant($user, $instance)) {
            return true;
        }
        $definition = $instance->definition;
        if ($definition?->is_confidential) {
            return in_array($user->id, array_map('intval', $definition->restricted_user_ids ?? []), true);
        }

        return $user->can(WorkflowPermissions::MANAGE) || $user->can(WorkflowPermissions::AUDIT);
    }

    /** Restrict an instance query to what $user may see (lists, dashboards, reports). */
    public function scopeVisible(Builder $q, User $user, bool $asManager = false): Builder
    {
        $mine = $this->actingFor($user);
        $participant = fn ($w) => $w->where('requester_id', $user->id)
            ->orWhereIn('id', WorkflowTask::query()->select('instance_id')->where(fn ($t) => $t->whereIn('assignee_id', $mine)->orWhere('decided_by', $user->id)->orWhere('original_assignee_id', $user->id)));

        if (! $asManager || ! ($user->can(WorkflowPermissions::MANAGE) || $user->can(WorkflowPermissions::AUDIT) || $user->can(WorkflowPermissions::REPORTS))) {
            return $q->where($participant);
        }

        // Everything ordinary, plus confidential ones the user is named on or takes part in.
        return $q->where(function ($w) use ($participant, $user) {
            $w->whereHas('definition', fn ($d) => $d->where('is_confidential', false))
                ->orWhereHas('definition', fn ($d) => $d->where('is_confidential', true)->whereJsonContains('restricted_user_ids', $user->id))
                ->orWhere($participant);
        });
    }
}
