<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Services\FarmTasks;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module G: farm tasks — assign, work, submit, and review by somebody other than the doer.
 */
class FarmTaskController extends AgriController
{
    public function __construct(private readonly FarmTasks $tasks) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_TASKS);
        $user = $request->user();
        $farm = $this->farmFilter($request);
        $view = $request->query('view', 'open');
        $people = collect($this->tenantPeopleOptions())->keyBy('id');

        $tasks = FarmTask::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->when($view === 'mine', fn ($q) => $q->where('assigned_to', $user->id)->whereNotIn('status', [FarmTask::APPROVED, FarmTask::CANCELLED]))
            ->when($view === 'review', fn ($q) => $q->where('status', FarmTask::SUBMITTED))
            ->when($view === 'open', fn ($q) => $q->whereIn('status', [...FarmTask::PENDING, FarmTask::SUBMITTED]))
            ->with(['farm', 'field'])->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('due_on')->limit(300)->get()
            ->map(fn (FarmTask $t) => [
                'id' => $t->uuid, 'title' => $t->title, 'description' => $t->description, 'priority' => $t->priority, 'status' => $t->status,
                'farm' => $t->farm?->name, 'farm_id' => $t->farm?->uuid, 'field' => $t->field?->code, 'due_on' => $t->due_on?->toDateString(),
                'overdue' => $t->isOverdue(), 'assigned_to' => $t->assigned_to, 'assignee' => $people[$t->assigned_to]['name'] ?? null,
                'requires_approval' => $t->requires_approval, 'labour_hours' => self::num($t->labour_hours),
                'completion_notes' => $t->completion_notes, 'review_note' => $t->review_note,
                'mine' => (int) $t->assigned_to === (int) $user->id,
                'can_review' => $t->status === FarmTask::SUBMITTED && $user->can(R::APPROVE_TASKS)
                    && (int) $t->submitted_by !== (int) $user->id && (int) $t->assigned_to !== (int) $user->id,
            ]);

        return Inertia::render('Modules/Agri/Tasks/Index', [
            'tasks' => $tasks,
            'counts' => [
                'mine' => FarmTask::query()->where('assigned_to', $user->id)->whereIn('status', FarmTask::PENDING)->count(),
                'review' => FarmTask::query()->where('status', FarmTask::SUBMITTED)->count(),
                'overdue' => FarmTask::query()->whereIn('status', FarmTask::PENDING)->whereDate('due_on', '<', today())->count(),
            ],
            'filters' => ['farm' => $farm?->uuid, 'view' => $view],
            'options' => [
                'farms' => $this->farmOptions(true), 'people' => $people->values(), 'priorities' => FarmTask::PRIORITIES,
                'plans' => CropPlan::query()->whereIn('status', CropPlan::WORKABLE)->with('farm:id,uuid')->get()->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'farm_id' => $p->farm?->uuid]),
                'groups' => LivestockGroup::query()->where('status', 'active')->with('farm:id,uuid')->get()->map(fn ($g) => ['id' => $g->id, 'code' => $g->code, 'farm_id' => $g->farm?->uuid]),
            ],
            'can' => ['manage' => $user->can(R::MANAGE_TASKS), 'approve' => $user->can(R::APPROVE_TASKS)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_TASKS);
        $data = $request->validate($this->rules() + ['farm_id' => ['required', 'string']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        $task = $this->tasks->create($farm, ['field_id' => $field?->id] + collect($data)->except(['farm_id', 'field_id'])->all(), $request->user());

        return back()->with('success', "Task \"{$task->title}\" created.");
    }

    public function update(Request $request, FarmTask $task): RedirectResponse
    {
        $this->allow($request, R::MANAGE_TASKS);
        $this->assertWritable($request, $task->farm_id);
        $data = $request->validate($this->rules());
        $field = $this->fieldOn($task->farm, $data['field_id'] ?? null);
        $this->tasks->update($task, ['field_id' => $field?->id] + collect($data)->except('field_id')->all());

        return back()->with('success', 'Task updated.');
    }

    public function transition(Request $request, FarmTask $task): RedirectResponse
    {
        $this->allow($request, R::VIEW_TASKS);
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'submit', 'approve', 'reject', 'cancel'])],
            'labour_hours' => ['nullable', 'numeric', 'gte:0', 'max:1000'],
            'completion_notes' => ['nullable', 'string', 'max:2000'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user();
        match ($data['action']) {
            'start' => $this->tasks->start($task, $user),
            'submit' => $this->tasks->submit($task, $data, $user),
            'approve', 'reject' => $this->reviewAs($request, $task, $data),
            'cancel' => $this->cancelAs($request, $task),
        };

        return back()->with('success', 'Task '.$task->fresh()->status.'.');
    }

    private function reviewAs(Request $request, FarmTask $task, array $data): void
    {
        $this->allow($request, R::APPROVE_TASKS);
        $this->assertWritable($request, $task->farm_id);
        $this->tasks->review($task, $data['action'] === 'approve', $data['review_note'] ?? null, $request->user());
    }

    private function cancelAs(Request $request, FarmTask $task): void
    {
        $this->allow($request, R::MANAGE_TASKS);
        $this->assertWritable($request, $task->farm_id);
        $this->tasks->cancel($task);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:3000'],
            'priority' => ['required', Rule::in(FarmTask::PRIORITIES)], 'due_on' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer'], 'field_id' => ['nullable', 'string'],
            'crop_plan_id' => ['nullable', 'integer'], 'livestock_group_id' => ['nullable', 'integer'],
            'requires_approval' => ['boolean'],
        ];
    }
}
