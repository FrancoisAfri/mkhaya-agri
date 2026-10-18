<?php

namespace App\Modules\Operations\ProjectManagement\Support\Presenters;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\TaskAttachment;
use App\Modules\Operations\ProjectManagement\Models\TaskComment;
use App\Modules\Operations\ProjectManagement\Models\TaskRecurrence;
use App\Modules\Operations\ProjectManagement\Support\Recurrence\Rrule;
use Illuminate\Support\Facades\Auth;

/**
 * The task drawer's payload (PM-1121, PM-1122).
 *
 * Names are resolved here rather than in the view: there is no `users.name` column on this
 * platform — a person's name lives on their HR record — and leaving that lookup to the client would
 * mean either an N+1 or a drawer full of "User 412".
 */
class TaskDetailPresenter
{
    /**
     * The drawer's payload for whichever task a screen was asked to open, or null for none.
     *
     * Both the work list and the board open the same drawer over themselves, and both get the task
     * from a `?task=` query string so a partial reload can refresh the conversation without
     * re-sending the list behind it. Keeping the lookup here is what stops the two screens drifting
     * into showing different things about the same task.
     *
     * Scoped to the project the caller already authorised: a uuid from another project finds
     * nothing rather than opening somebody else's work.
     *
     * @return array<string,mixed>|null
     */
    public function forProject(Project $project, mixed $taskUuid): ?array
    {
        if (! is_string($taskUuid) || $taskUuid === '') {
            return null;
        }

        $task = Task::where('project_id', $project->id)->where('uuid', $taskUuid)->first();

        return $task ? $this->present($task) : null;
    }

    /** @return array<string,mixed> */
    public function present(Task $task): array
    {
        $comments = TaskComment::where('task_id', $task->id)->orderBy('id')->get();
        $checklist = ChecklistItem::where('task_id', $task->id)->orderBy('position')->get();
        $attachments = TaskAttachment::where('task_id', $task->id)->orderByDesc('id')->get();
        $watchers = $task->watchers()->get();

        // Who is actually doing this. The drawer showed a task's checklist, its conversation, its
        // files and who was watching it — everything except the one thing somebody opens a task to
        // find out.
        $assignments = TaskAssignment::where('task_id', $task->id)
            ->with('member:id,uuid,display_name,role_key')
            ->orderByDesc('is_lead')
            ->get();
        $recurrence = TaskRecurrence::where('template_task_id', $task->id)->first();

        $names = $this->names(
            $comments->pluck('author_id')
                ->merge($checklist->pluck('done_by'))
                ->merge($attachments->pluck('uploaded_by'))
                ->merge($watchers->pluck('user_id'))
        );

        $me = (int) Auth::id();

        return [
            'id'   => $task->uuid,
            'code' => $task->code,
            'name' => $task->name,
            // Carried so the drawer can archive and restore. Archiving is the one status change
            // that has nowhere else to be made: the board draws no column for it, by design.
            'status' => $task->status,

            /*
             * The task's own fields, so it can be edited where it is read.
             *
             * Opening a task showed its conversation, its files and who was on it, and offered no
             * way to correct the one thing people most often need to: the wording of the task, and
             * when it is due. That meant deleting and retyping — which loses the comments, the
             * files and the history attached to it.
             */
            'description'    => $task->description,
            'priority'       => $task->priority,
            'planned_start'  => $task->planned_start?->toDateString(),
            'planned_end'    => $task->planned_end?->toDateString(),

            'checklist' => $checklist->map(fn (ChecklistItem $i) => [
                'id'      => $i->uuid,
                'text'    => $i->text,
                'is_done' => (bool) $i->is_done,
                'done_by' => $names[(int) $i->done_by] ?? null,
                'done_at' => $i->done_at?->toDateTimeString(),
            ])->all(),

            // Flat, with parent ids: the client nests one level, which is all the threading there is.
            'comments' => $comments->map(fn (TaskComment $c) => [
                'id'        => $c->uuid,
                'parent_id' => $comments->firstWhere('id', $c->parent_comment_id)?->uuid,
                'body'      => $c->body,
                'author'    => $names[(int) $c->author_id] ?? __('project_management.tasks.someone'),
                'is_mine'   => (int) $c->author_id === $me,
                'created_at' => $c->created_at?->diffForHumans(),
                'edited'    => $c->edited_at !== null,
            ])->all(),

            'watchers' => $watchers->map(fn ($w) => [
                'id'       => (int) $w->user_id,
                'name'     => $names[(int) $w->user_id] ?? __('project_management.tasks.someone'),
                'source'   => $w->source,
                'is_muted' => (bool) $w->is_muted,
            ])->all(),

            'assignees' => $assignments->map(fn (TaskAssignment $a) => [
                'id'             => $a->member?->uuid,
                'name'           => $a->member?->display_name,
                'role'           => $a->role ?: $a->member?->role_key,
                'allocation_pct' => (int) $a->allocation_pct,
                'is_lead'        => (bool) $a->is_lead,
            ])->filter(fn ($a) => $a['id'] !== null)->values()->all(),

            // The team, so the drawer can offer somebody rather than ask you to type a name.
            'team' => ProjectMember::where('project_id', $task->project_id)->active()
                ->orderBy('display_name')
                ->get(['uuid', 'display_name', 'role_key'])
                ->map(fn (ProjectMember $m) => [
                    'id' => $m->uuid, 'name' => $m->display_name, 'role' => $m->role_key,
                ])->all(),

            /*
             * Who can be tagged in a comment — members who have a login behind them.
             *
             * Keyed by **user** id rather than member uuid, because that is what a mention is: the
             * comment stores user ids, the watcher subscription takes a user id, and the person
             * notified is a user. A subcontractor's foreman with no account cannot be tagged, and
             * offering them would produce a mention that reaches nobody.
             */
            'taggable' => ProjectMember::where('project_id', $task->project_id)->active()
                ->whereNotNull('user_id')
                ->orderBy('display_name')
                ->get(['user_id', 'display_name'])
                ->map(fn (ProjectMember $m) => ['id' => (int) $m->user_id, 'name' => $m->display_name])
                ->unique('id')
                ->values()
                ->all(),

            'is_watching' => $watchers->contains(fn ($w) => (int) $w->user_id === $me),

            'attachments' => $attachments->map(fn (TaskAttachment $a) => [
                'id'       => $a->uuid,
                'filename' => $a->filename,
                'size'     => $a->size_bytes,
                'by'       => $names[(int) $a->uploaded_by] ?? null,
                'is_mine'  => (int) $a->uploaded_by === $me,
            ])->all(),

            'recurrence' => $recurrence ? [
                'id'          => $recurrence->uuid,
                'rrule'       => $recurrence->rrule,
                // The rule in words. A stored RRULE is unreadable to the person who has to trust it.
                'description' => $this->describe($recurrence->rrule),
                'is_active'   => (bool) $recurrence->is_active,
                'generated'   => (int) $recurrence->generated_count,
                'next_after'  => $recurrence->last_occurrence_at?->toDateString(),
            ] : null,

            'occurrence_of' => $task->occurrence_of_id ? [
                'date' => $task->occurrence_date?->toDateString(),
            ] : null,
        ];
    }

    /** @return array<int,string> */
    private function names(\Illuminate\Support\Collection $ids): array
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::with('person')
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (User $u) => [
                $u->id => trim(($u->person->first_name ?? '') . ' ' . ($u->person->surname ?? '')) ?: $u->email,
            ])
            ->all();
    }

    private function describe(string $rrule): string
    {
        try {
            return Rrule::parse($rrule)->describe();
        } catch (\InvalidArgumentException) {
            // A rule stored by an older version, or through the API, that this build cannot read.
            // Showing the raw string beats showing nothing.
            return $rrule;
        }
    }
}
