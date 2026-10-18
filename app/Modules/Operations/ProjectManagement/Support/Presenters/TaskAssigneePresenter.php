<?php

namespace App\Modules\Operations\ProjectManagement\Support\Presenters;

use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use Illuminate\Support\Collection;

/**
 * Who a task is allocated to, for every screen that lists tasks.
 *
 * Assignment was recorded and shown nowhere. The board drew a card with a code, a name and a date;
 * the work list drew a row with a status and a progress bar; the agile board asked a Task for an
 * `assignee_display` attribute that has never existed on the model, so it rendered null on every
 * story since the day it was written. Somebody looking at any of those screens could not tell work
 * that had an owner from work that had none — which is the first question anybody asks of a board.
 *
 * One query for a whole page of tasks. The alternative — a relation loaded per row — is how a
 * twenty-task board becomes forty queries and a five-hundred-task programme stops loading at all.
 */
class TaskAssigneePresenter
{
    /**
     * Assignees for each of the given task ids, keyed by task id.
     *
     * The lead comes first. A task with four people on it has one who is answerable for it, and a
     * list that ordered them by insertion would put whoever happened to be added first at the front
     * of the stack — which is the name everybody then reads as the owner.
     *
     * @param  iterable<int>  $taskIds
     * @return array<int, list<array{id: ?string, name: ?string, is_lead: bool, role: ?string}>>
     */
    public function forTasks(iterable $taskIds): array
    {
        $ids = collect($taskIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return TaskAssignment::whereIn('task_id', $ids)
            ->with('member:id,uuid,display_name,role_key')
            ->orderByDesc('is_lead')
            ->orderBy('id')
            ->get()
            ->groupBy('task_id')
            ->map(fn (Collection $rows) => $rows
                // A membership deleted out from under an assignment leaves a row pointing at
                // nobody. Drawing it as a blank face is worse than drawing nothing.
                ->filter(fn (TaskAssignment $a) => $a->member !== null)
                ->map(fn (TaskAssignment $a) => [
                    'id'      => $a->member->uuid,
                    'name'    => $a->member->display_name,
                    'is_lead' => (bool) $a->is_lead,
                    'role'    => $a->role ?: $a->member->role_key,
                ])
                ->values()
                ->all())
            ->all();
    }

    /**
     * The same, ready to splice into a payload the caller is already building.
     *
     * Returns an empty list rather than null for an unassigned task, so every consumer can iterate
     * without first asking whether there is anything to iterate.
     *
     * @param  array<int, list<array<string, mixed>>>  $byTask
     * @return list<array<string, mixed>>
     */
    public function of(array $byTask, ?int $taskId): array
    {
        return $byTask[$taskId] ?? [];
    }
}
