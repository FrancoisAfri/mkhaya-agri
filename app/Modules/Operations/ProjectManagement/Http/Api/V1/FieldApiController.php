<?php

namespace App\Modules\Operations\ProjectManagement\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\CollaborationService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;
use App\Modules\Operations\ProjectManagement\Support\Api\ProblemDetails;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\Rule;

/**
 * What a phone sends back after a day on site (EPIC-061).
 *
 * Every write here is replay-safe. A device that loses signal mid-sync retries the whole queue, and
 * the only acceptable outcome is that the second attempt changes nothing — hence Idempotency-Key on
 * the routes, and last-write-wins on the fields that are genuinely a person's latest word.
 *
 * Status and checklist ticks are conflict-free by construction: they are declarations of a final
 * state, not increments, so two devices arriving in either order settle on the same answer. Anything
 * that needed merging would be evidence, and evidence is append-only (PM-ADR-002).
 */
class FieldApiController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly CollaborationService $collaboration,
        private readonly TimesheetService $timesheets,
    ) {
    }

    /** Mark a task started, blocked or done from the field. */
    public function updateTask(Request $request, string $uuid): Response
    {
        $task = Task::where('uuid', $uuid)->first();

        if (! $task) {
            return ProblemDetails::notFound('No task with that id is visible to you.');
        }

        if ($request->user()->cannot('update', $task)) {
            return ProblemDetails::forbidden('You may not change this task.');
        }

        $data = $request->validate([
            'status'       => ['nullable', Rule::in(Task::STATUSES)],
            'progress_pct' => ['nullable', 'numeric', 'between:0,100'],
            // The device's clock, so a status change recorded underground carries the moment it
            // actually happened rather than the moment the signal came back.
            'occurred_at'  => ['nullable', 'date'],
        ]);

        if (($data['status'] ?? null) === null && ($data['progress_pct'] ?? null) === null) {
            return ProblemDetails::unprocessable('Send a status, a progress figure, or both.');
        }

        $this->tasks->update($task, array_filter([
            'status'       => $data['status'] ?? null,
            'progress_pct' => $data['progress_pct'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json([
            'id'           => $task->uuid,
            'status'       => $task->fresh()->status,
            'progress_pct' => (float) $task->fresh()->progress_pct,
        ]);
    }

    /** Tick or untick a checklist item. */
    public function updateChecklistItem(Request $request, string $uuid): Response
    {
        $item = ChecklistItem::where('uuid', $uuid)->first();

        if (! $item) {
            return ProblemDetails::notFound('No checklist item with that id is visible to you.');
        }

        if ($request->user()->cannot('collaborate', $item->task)) {
            return ProblemDetails::forbidden('You may not change this checklist.');
        }

        $data = $request->validate(['is_done' => ['required', 'boolean']]);

        $this->collaboration->setChecklistItemDone($item, (bool) $data['is_done']);

        return response()->json(['id' => $item->uuid, 'is_done' => (bool) $item->fresh()->is_done]);
    }

    /** Book hours from the field. They arrive submitted, never approved. */
    public function logTime(Request $request): Response
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'task_id'    => ['nullable', 'uuid'],
            'worked_on'  => ['required', 'date'],
            'hours'      => ['required', 'numeric', 'min:0.25', 'max:' . TimesheetService::MAX_HOURS_PER_DAY],
            'note'       => ['nullable', 'string', 'max:500'],
        ]);

        $project = Project::where('uuid', $data['project_id'])->first();

        if (! $project) {
            return ProblemDetails::notFound('No project with that id is visible to you.');
        }

        if ($request->user()->cannot('view', $project)) {
            return ProblemDetails::forbidden('You may not book time against this project.');
        }

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $member) {
            // Booking time against a job you are not on is either a mistake or a fiddle, and the
            // person can be added to the project in a second if it is the former.
            return ProblemDetails::unprocessable('You are not a member of that project, so hours cannot be booked against it.');
        }

        try {
            $entry = $this->timesheets->log($project, $member, $data);
            $this->timesheets->submit($entry);
        } catch (\InvalidArgumentException $e) {
            return ProblemDetails::unprocessable($e->getMessage());
        }

        return response()->json([
            'id'        => $entry->uuid,
            'status'    => $entry->fresh()->status,
            'hours'     => (float) $entry->hours,
            'worked_on' => $entry->worked_on->toDateString(),
        ], 201);
    }
}
