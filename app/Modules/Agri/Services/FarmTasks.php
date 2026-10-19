<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Support\RoleCatalog;
use Illuminate\Validation\ValidationException;

/**
 * Farm work given to people and checked when done (spec Module G).
 *
 *     open ─start─▶ in_progress ─submit─▶ submitted ─approve─▶ approved
 *       └────────────submit──────────────▶     └──reject──▶ rejected ─(rework)─▶ in_progress
 *
 * Work is assigned only to somebody who can reach the farm. Only the assignee submits it; a
 * submission is reviewed by somebody else — nobody approves their own work. A task that does not
 * need approval is approved by its submission.
 */
class FarmTasks
{
    public function __construct(private readonly FarmAccess $access, private readonly FarmRegistry $registry) {}

    public function create(Farm $farm, array $data, User $actor): FarmTask
    {
        $this->registry->assertOperational($farm);
        $this->assertAssignable($farm, $data['assigned_to'] ?? null);
        $this->assertLinksOn($farm, $data);

        return FarmTask::create($data + [
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'status' => FarmTask::OPEN, 'created_by' => $actor->id,
        ]);
    }

    public function update(FarmTask $task, array $data): FarmTask
    {
        if (! in_array($task->status, FarmTask::PENDING, true)) {
            throw ValidationException::withMessages(['status' => "A {$task->status} task cannot be changed."]);
        }
        $this->assertAssignable($task->farm, $data['assigned_to'] ?? null);
        $this->assertLinksOn($task->farm, $data);
        $task->update($data);

        return $task;
    }

    public function start(FarmTask $task, User $actor): FarmTask
    {
        $this->assertAssignee($task, $actor);
        if (! in_array($task->status, [FarmTask::OPEN, FarmTask::REJECTED], true)) {
            throw ValidationException::withMessages(['status' => "A {$task->status} task cannot be started."]);
        }
        $task->update(['status' => FarmTask::IN_PROGRESS]);

        return $task;
    }

    public function submit(FarmTask $task, array $data, User $actor): FarmTask
    {
        $this->assertAssignee($task, $actor);
        if (! in_array($task->status, FarmTask::PENDING, true)) {
            throw ValidationException::withMessages(['status' => "A {$task->status} task cannot be submitted."]);
        }
        $task->update([
            'status' => $task->requires_approval ? FarmTask::SUBMITTED : FarmTask::APPROVED,
            'labour_hours' => $data['labour_hours'] ?? $task->labour_hours,
            'completion_notes' => $data['completion_notes'] ?? null,
            'submitted_at' => now(), 'submitted_by' => $actor->id,
        ] + ($task->requires_approval ? [] : ['reviewed_at' => now(), 'reviewed_by' => $actor->id]));

        return $task;
    }

    public function review(FarmTask $task, bool $approve, ?string $note, User $actor): FarmTask
    {
        if ($task->status !== FarmTask::SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'Only submitted work can be reviewed.']);
        }
        if ((int) $task->submitted_by === (int) $actor->id || (int) $task->assigned_to === (int) $actor->id) {
            throw ValidationException::withMessages(['status' => 'You did this work, so somebody else must review it.']);
        }
        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['review_note' => 'Say what needs to be redone.']);
        }
        $task->update([
            'status' => $approve ? FarmTask::APPROVED : FarmTask::REJECTED,
            'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note,
        ]);

        return $task;
    }

    public function cancel(FarmTask $task): FarmTask
    {
        if (! in_array($task->status, FarmTask::PENDING, true)) {
            throw ValidationException::withMessages(['status' => "A {$task->status} task cannot be cancelled."]);
        }
        $task->update(['status' => FarmTask::CANCELLED]);

        return $task;
    }

    /** A task's crop plan, herd or machine must be on the task's own farm. */
    private function assertLinksOn(Farm $farm, array $data): void
    {
        foreach (['crop_plan_id' => \App\Modules\Agri\Models\CropPlan::class, 'livestock_group_id' => \App\Modules\Agri\Models\LivestockGroup::class,
            'machine_id' => \App\Modules\Agri\Models\FarmMachine::class] as $key => $class) {
            if (! empty($data[$key]) && ! $class::query()->whereKey($data[$key])->where('farm_id', $farm->id)->exists()) {
                throw ValidationException::withMessages([$key => 'That is not on this farm.']);
            }
        }
    }

    private function assertAssignee(FarmTask $task, User $actor): void
    {
        $isAssignee = (int) $task->assigned_to === (int) $actor->id;
        $mayManage = $actor->can(RoleCatalog::MANAGE_TASKS) && $task->assigned_to === null;
        if (! $isAssignee && ! $mayManage) {
            throw ValidationException::withMessages(['status' => 'Only the person this task is assigned to can work on it.']);
        }
    }

    private function assertAssignable(Farm $farm, $userId): void
    {
        if (! $userId) {
            return;
        }
        $user = User::query()->find($userId);
        if (! $user || (int) $user->company_id !== (int) $farm->company_id || ! $this->access->canReach($user, $farm->id)) {
            throw ValidationException::withMessages(['assigned_to' => 'That person does not have access to this farm. Give them access first.']);
        }
    }
}
