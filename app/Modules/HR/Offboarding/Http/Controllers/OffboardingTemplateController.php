<?php

namespace App\Modules\HR\Offboarding\Http\Controllers;

use App\Modules\HR\Offboarding\Models\OffboardingPlan;
use App\Modules\HR\Offboarding\Models\OffboardingTemplate;
use App\Modules\HR\Offboarding\Models\OffboardingTemplateTask;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use App\Core\Role\Models\Role;

class OffboardingTemplateController
{
    public function index(Request $request): Response
    {
        $templates = OffboardingTemplate::withCount('templateTasks')
            ->when($request->input('search'), fn ($q, $s) =>
                $q->where('title', 'ilike', "%{$s}%")
                  ->orWhere('description', 'ilike', "%{$s}%")
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'       => OffboardingTemplate::count(),
            'task_count'  => OffboardingTemplateTask::count(),
        ];

        return Inertia::render('Modules/Offboarding/Templates/Index', [
            'templates' => $templates->through(fn ($template) => [
                'id'                  => $template->id,
                'uuid'                => $template->uuid,
                'title'               => $template->title,
                'description'         => $template->description,
                'notice_period_days'  => $template->notice_period_days,
                'template_tasks_count' => $template->template_tasks_count,
                'created_at'          => $template->created_at?->toDateString(),
            ]),
            'filters' => $request->only('search'),
            'stats'   => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'               => ['required', 'string', 'max:150'],
            'description'         => ['nullable', 'string'],
            'notice_period_days'  => ['integer', 'min:0', 'max:365'],
        ]);

        $template = OffboardingTemplate::create(array_merge($validated, [
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('offboarding.templates.show', $template);
    }

    public function show(OffboardingTemplate $template): Response
    {
        $template->load('templateTasks');

        return Inertia::render('Modules/Offboarding/Templates/Show', [
            'template' => [
                'id'                 => $template->id,
                'uuid'               => $template->uuid,
                'title'              => $template->title,
                'description'        => $template->description,
                'notice_period_days' => $template->notice_period_days,
                'tasks'              => $template->templateTasks->map(fn ($task) => [
                    'id'                  => $task->id,
                    'title'               => $task->title,
                    'description'         => $task->description,
                    'category'            => $task->category,
                    'assigned_role'       => $task->assigned_role,
                    'days_before_last_day' => $task->days_before_last_day,
                    'sort_order'          => $task->sort_order,
                ])->values(),
            ],
            'roles' => Role::orderBy('name')->pluck('name')->values(),
        ]);
    }

    public function update(Request $request, OffboardingTemplate $template)
    {
        $validated = $request->validate([
            'title'               => ['sometimes', 'required', 'string', 'max:150'],
            'description'         => ['nullable', 'string'],
            'notice_period_days'  => ['integer', 'min:0', 'max:365'],
        ]);

        $template->update($validated);

        return back();
    }

    public function destroy(OffboardingTemplate $template)
    {
        $activePlanCount = OffboardingPlan::where('template_id', $template->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        if ($activePlanCount > 0) {
            throw ValidationException::withMessages([
                'template' => 'This template cannot be deleted because it is used by ' . $activePlanCount . ' active offboarding plan(s).',
            ]);
        }

        $template->delete();

        return redirect()->route('offboarding.templates.index');
    }

    public function storeTask(Request $request, OffboardingTemplate $template)
    {
        $validated = $request->validate([
            'title'               => ['required', 'string', 'max:200'],
            'description'         => ['nullable', 'string'],
            'category'            => ['required', 'string', 'in:hr,it,finance,facilities,manager'],
            'assigned_role'       => ['nullable', 'string', 'max:100'],
            'days_before_last_day' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $maxOrder = $template->templateTasks()->max('sort_order') ?? -1;

        OffboardingTemplateTask::create([
            'template_id'         => $template->id,
            'title'               => $validated['title'],
            'description'         => $validated['description'] ?? null,
            'category'            => $validated['category'],
            'assigned_role'       => $validated['assigned_role'] ?? null,
            'days_before_last_day' => $validated['days_before_last_day'] ?? 0,
            'sort_order'          => $maxOrder + 1,
        ]);

        return back();
    }

    public function updateTask(Request $request, OffboardingTemplate $template, OffboardingTemplateTask $task)
    {
        abort_unless($task->template_id === $template->id, 404);

        $validated = $request->validate([
            'title'               => ['sometimes', 'required', 'string', 'max:200'],
            'description'         => ['nullable', 'string'],
            'category'            => ['sometimes', 'required', 'string', 'in:hr,it,finance,facilities,manager'],
            'assigned_role'       => ['nullable', 'string', 'max:100'],
            'days_before_last_day' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $task->update($validated);

        return back();
    }

    public function destroyTask(OffboardingTemplate $template, OffboardingTemplateTask $task)
    {
        abort_unless($task->template_id === $template->id, 404);

        $task->delete();

        return back();
    }
}
