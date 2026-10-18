<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Notifications\TaskCompletedNotification;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tasks (PM-1120): the unit of work people actually tick off.
 *
 * Subtasks nest to any depth, and a parent aggregates from its children rather than being edited
 * directly — a summary task whose dates disagree with the work under it is worse than no summary
 * at all, because people plan against it.
 *
 * The scheduling columns (early/late dates, float, is_critical) are never written here. They are
 * output of the CPM engine (PM-ADR-005), and a service that let a form set them would let a user
 * fabricate a critical path.
 */
class TaskService
{
    /** One request may edit this many tasks (PM-1120). Beyond it, the caller pages. */
    public const BULK_LIMIT = 200;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly InternalEmitter $emitter,
    ) {
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(Project $project, array $attributes, ?Task $parent = null, ?WbsNode $wbsNode = null): Task
    {
        if ($parent && $parent->project_id !== $project->id) {
            throw new InvalidArgumentException('The parent task belongs to a different project.');
        }

        return DB::transaction(function () use ($project, $attributes, $parent, $wbsNode) {
            $task = new Task($attributes);
            $task->project_id = $project->id;
            $task->parent_task_id = $parent?->id;
            // A subtask inherits its parent's place in the breakdown unless told otherwise, so the
            // WBS stays coherent without the user restating it.
            $task->wbs_node_id = $wbsNode?->id ?? $parent?->wbs_node_id;
            $task->code = $attributes['code'] ?? $this->nextCode($project);
            $task->currency = $attributes['currency'] ?? $project->currency;
            // Set explicitly rather than left to the column defaults: the database would fill them
            // in, but the in-memory model would not know, and the very next update() would compare
            // against a null it never had.
            $task->status ??= 'not_started';
            $task->priority ??= 'medium';
            $task->save();

            if ($parent) {
                $this->rollUp($parent->fresh());
            }

            $this->outbox->publish('pm.task.created', 'task', $task->uuid, [
                'project_uuid' => $project->uuid,
                'code'         => $task->code,
                'name'         => $task->name,
            ]);

            return $task;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Task $task, array $data): Task
    {
        $completed = false;

        $task = DB::transaction(function () use ($task, $data, &$completed) {
            $completed = $this->write($task, $data);

            return $task;
        });

        /*
         * Announced after the write has landed, never inside it. A mail sent from inside a
         * transaction that then rolls back has still been sent, and the recipient is left holding a
         * notice about a completion that never happened.
         *
         * `bulkUpdate` deliberately does not reach this path — it wraps its own transaction around
         * a loop, so anything announced here would still be inside one. It announces its own.
         */
        if ($completed) {
            $this->announceCompletion($task);
        }

        return $task;
    }

    /**
     * The write itself, with no announcement, for callers that own the surrounding transaction.
     *
     * @param  array<string,mixed>  $data
     * @return bool  whether this edit is what moved the task into 'done'
     */
    private function write(Task $task, array $data): bool
    {
        // Neither the public id nor the engine's output is editable.
        unset(
            $data['uuid'], $data['company_id'], $data['project_id'],
            $data['early_start'], $data['early_finish'], $data['late_start'], $data['late_finish'],
            $data['total_float_minutes'], $data['free_float_minutes'], $data['is_critical'],
        );

        $previousStatus = $task->status;

        // Read before the write, and only a real transition counts: re-saving a task that is
        // already done must not tell anybody it has just been finished.
        $justCompleted = ($data['status'] ?? null) === 'done' && $previousStatus !== 'done';

        $task->fill($data)->save();

        if (isset($data['status']) && $data['status'] !== $previousStatus) {
            $this->recordStatusChange($task, $previousStatus);
        }

        if ($task->parent_task_id) {
            $this->rollUp($task->parent->fresh());
        }

        return $justCompleted;
    }

    /**
     * Tell whoever is answerable for a task that somebody has marked it done.
     *
     * A card can be dragged into Done by anybody who may edit the project, so until now the person
     * whose work it was found out by happening to look at the board.
     *
     * Assignees and the person who raised it. Not watchers — watching is a subscription to a
     * conversation, and a completion notice sent to everyone who ever commented is how a useful
     * channel becomes one people mute.
     *
     * Never the person who did it: telling somebody what they have just themselves done is noise,
     * and noise is what makes the rest of it ignorable.
     */
    private function announceCompletion(Task $task): void
    {
        $actorId = Auth::id();

        $userIds = TaskAssignment::where('task_id', $task->id)
            ->with('member:id,user_id')
            ->get()
            ->map(fn (TaskAssignment $a) => $a->member?->user_id)
            ->push($task->created_by)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === (int) $actorId)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $actor = $actorId ? User::query()->with('person')->find($actorId) : null;

        // Names live on the HR record on this platform; there is no `users.name` column.
        $completedBy = $actor?->person
            ? trim($actor->person->first_name . ' ' . $actor->person->surname) ?: $actor->email
            : $actor?->email;

        $recipients = User::query()->whereIn('id', $userIds)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TaskCompletedNotification($task, $completedBy));
        }
    }

    /**
     * Edit many tasks at once — the "set these forty to blocked" case that is otherwise forty
     * requests and forty audit rows nobody reads.
     *
     * @param  list<string>  $uuids
     * @param  array<string,mixed>  $data
     * @return int  how many were changed
     */
    public function bulkUpdate(array $uuids, array $data): int
    {
        if (count($uuids) > self::BULK_LIMIT) {
            throw new InvalidArgumentException(
                'A bulk edit is limited to ' . self::BULK_LIMIT . ' tasks; split the selection.'
            );
        }

        $completed = [];

        $changed = DB::transaction(function () use ($uuids, $data, &$completed) {
            $tasks = Task::whereIn('uuid', $uuids)->get();
            $count = 0;

            foreach ($tasks as $task) {
                if ($this->write($task, $data)) {
                    $completed[] = $task;
                }

                $count++;
            }

            return $count;
        });

        // Outside the transaction, for the same reason as the single edit: a notice about a
        // completion that was rolled back cannot be unsent.
        foreach ($completed as $task) {
            $this->announceCompletion($task);
        }

        return $changed;
    }

    /**
     * Soft-delete a task and its subtasks.
     *
     * Dependencies referencing the deleted tasks go too: a link to a task nobody can see would
     * constrain the schedule invisibly, which is the worst kind of scheduling bug to diagnose.
     */
    public function delete(Task $task): void
    {
        DB::transaction(function () use ($task) {
            $ids = $this->descendantIds($task);
            $ids[] = $task->id;

            DB::table('pm_task_dependencies')
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereIn('predecessor_id', $ids)->orWhereIn('successor_id', $ids))
                ->update(['deleted_at' => now()]);

            Task::whereIn('id', $ids)->delete();

            if ($task->parent_task_id) {
                $this->rollUp($task->parent->fresh());
            }

            $this->outbox->publish('pm.task.deleted', 'task', $task->uuid, [
                'project_uuid' => $task->project->uuid,
                'subtasks'     => count($ids) - 1,
            ]);
        });
    }

    /**
     * Recompute a parent from its children (PM-1120): earliest start, latest finish, and progress
     * weighted by duration.
     *
     * Weighted, not averaged: a two-hour task at 100% and a two-week task at 0% is not half done,
     * and a summary that says so would be actively misleading.
     */
    public function rollUp(Task $parent): Task
    {
        $children = Task::where('parent_task_id', $parent->id)->get();

        if ($children->isEmpty()) {
            return $parent;
        }

        $totalWeight = (int) $children->sum(fn (Task $c) => max(1, (int) $c->duration_minutes));
        $weighted = $children->sum(fn (Task $c) => max(1, (int) $c->duration_minutes) * (float) $c->progress_pct);

        $parent->forceFill([
            'planned_start' => $children->min('planned_start'),
            'planned_end'   => $children->max('planned_end'),
            'actual_start'  => $children->min('actual_start'),
            // Only complete when every child is: a parent cannot finish before its work does.
            'actual_end'    => $children->every(fn (Task $c) => $c->actual_end !== null)
                ? $children->max('actual_end')
                : null,
            'progress_pct'  => $totalWeight > 0 ? round($weighted / $totalWeight, 2) : 0,
            'progress_source' => 'derived',
        ])->save();

        // Roll up the chain: a grandparent is just as wrong as a parent would have been.
        if ($parent->parent_task_id) {
            $this->rollUp($parent->parent->fresh());
        }

        return $parent;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A status change is evidence, recorded in the same transaction (PM-1004).
     *
     * `task.started` and `task.completed` are the two the derivation engine cares about; other
     * transitions are recorded as a generic change so the timeline stays complete.
     */
    private function recordStatusChange(Task $task, ?string $previousStatus): void
    {
        // Reopening is checked first: coming back from 'done' to 'in_progress' is a reopening,
        // not a fresh start, and matching on the new status alone would have recorded it as one —
        // quietly turning a retracted completion into a second beginning.
        $eventType = match (true) {
            $previousStatus === 'done' && $task->status !== 'done' => 'task.reopened',
            $task->status === 'in_progress'                        => 'task.started',
            $task->status === 'done'                               => 'task.completed',
            default                                                => 'status.changed',
        };

        $payload = ['from' => $previousStatus ?? 'unknown', 'to' => $task->status];

        // task.reopened requires a reason by its contract; without one the ingestor would
        // dead-letter an event PM itself produced.
        if ($eventType === 'task.reopened') {
            $payload['reason'] = $task->description ?: 'Reopened without a stated reason.';
        }

        $this->emitter->emit($task->project, $eventType, [
            'subject_type' => 'task',
            'subject_id'   => $task->uuid,
            'source_ref'   => "task:{$task->id}:status:{$task->status}",
            'payload'      => $payload,
        ]);
    }

    /** @return list<int> */
    private function descendantIds(Task $task): array
    {
        $ids = [];
        $frontier = [$task->id];

        // Iterative rather than recursive: subtasks nest to any depth, and a deep tree should not
        // depend on PHP's stack.
        while ($frontier !== []) {
            $children = Task::whereIn('parent_task_id', $frontier)->pluck('id')->all();

            if ($children === []) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    private function nextCode(Project $project): string
    {
        $last = Task::withTrashed()
            ->where('project_id', $project->id)
            ->where('code', 'like', 'T-%')
            ->orderByDesc('id')
            ->value('code');

        $next = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return 'T-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
