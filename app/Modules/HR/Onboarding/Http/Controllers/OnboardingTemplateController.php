<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\Position\Models\HrPosition;
use App\Modules\HR\Onboarding\Actions\CreateOnboardingTemplateAction;
use App\Modules\HR\Onboarding\Http\Resources\OnboardingTemplateResource;
use App\Modules\HR\Onboarding\Models\OnboardingTemplate;
use App\Modules\HR\Onboarding\Models\OnboardingTemplateTask;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingTemplateController
{
    public function index(Request $request): Response
    {
        $templates = OnboardingTemplate::withCount('templateTasks')
            ->when($request->input('search'), fn($q, $s) =>
                $q->where('name', 'ilike', "%{$s}%")
                  ->orWhere('department', 'ilike', "%{$s}%")
                  ->orWhere('job_title', 'ilike', "%{$s}%")
            )
            ->when($request->filled('is_active'), fn($q) =>
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN))
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'  => OnboardingTemplate::count(),
            'active' => OnboardingTemplate::where('is_active', true)->count(),
        ];

        $departments = CompanyUnit::where('type', 'department')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        $positions = HrPosition::where('status', 1)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]);

        return Inertia::render('Modules/Onboarding/Templates/Index', [
            'templates'   => OnboardingTemplateResource::collection($templates),
            'filters'     => $request->only('search', 'is_active'),
            'stats'       => $stats,
            'departments' => $departments,
            'positions'   => $positions,
        ]);
    }

    public function show(OnboardingTemplate $template): Response
    {
        $template->load('templateTasks');

        $departments = CompanyUnit::where('type', 'department')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        $positions = HrPosition::where('status', 1)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]);

        return Inertia::render('Modules/Onboarding/Templates/Show', [
            'template'    => (new OnboardingTemplateResource($template))->resolve(),
            'departments' => $departments,
            'positions'   => $positions,
        ]);
    }

    public function store(Request $request, CreateOnboardingTemplateAction $action)
    {
        $validated = $request->validate([
            'name'                    => ['required', 'string', 'max:150'],
            'description'             => ['nullable', 'string'],
            'department'              => ['nullable', 'string', 'max:100'],
            'job_title'               => ['nullable', 'string', 'max:100'],
            'employment_type'         => ['nullable', 'string', 'in:permanent,contract,part_time'],
            'is_active'               => ['boolean'],
            'tasks'                   => ['nullable', 'array'],
            'tasks.*.type'            => ['required', 'string'],
            'tasks.*.title'           => ['required', 'string', 'max:200'],
            'tasks.*.description'     => ['nullable', 'string'],
            'tasks.*.day_offset'      => ['nullable', 'integer', 'min:0'],
            'tasks.*.owner_type'      => ['nullable', 'string', 'in:hr,it,manager,buddy'],
            'tasks.*.sort_order'      => ['nullable', 'integer', 'min:0'],
        ]);

        $template = $action->execute($validated, $request->user()->id);

        return redirect()->route('onboarding.templates.show', $template->uuid);
    }

    /**
     * The shared catalogue is read-only to every tenant.
     *
     * RLS already refuses the write — a tenant's UPDATE matches no rows — but silently: the request
     * would return 302 and the user would watch their change not happen. This turns that into an
     * answer. Copy the template first; `copy()` is the supported way to change one.
     */
    private function refuseIfGlobal(OnboardingTemplate $template): void
    {
        if ($template->company_id === null) {
            throw ValidationException::withMessages([
                'template' => 'This is a shared template from the platform catalogue. Copy it to your '
                    . 'company first, then edit the copy.',
            ]);
        }
    }

    /**
     * Copy a catalogue template into the current company, tasks and all.
     *
     * A copy rather than a reference: a tenant that adjusts a template should not have the platform
     * change it underneath them on the next catalogue update, and the catalogue should not carry
     * one tenant's edits to everyone else.
     */
    public function copy(Request $request, OnboardingTemplate $template)
    {
        $companyId = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id()
            ?? $request->user()->company_id;

        abort_if($companyId === null, 422, 'No active company to copy this template into.');

        $copy = OnboardingTemplate::create([
            'name'                    => $template->name,
            'description'             => $template->description,
            'department'              => $template->department,
            'job_title'               => $template->job_title,
            'employment_type'         => $template->employment_type,
            'industry'                => $template->industry,
            'is_active'               => true,
            'company_id'              => $companyId,
            'created_by'              => $request->user()->id,
            'copied_from_template_id' => $template->id,
        ]);

        foreach ($template->templateTasks as $task) {
            $copy->templateTasks()->create([
                'type'        => $task->type,
                'title'       => $task->title,
                'description' => $task->description,
                'day_offset'  => $task->day_offset,
                'owner_type'  => $task->owner_type,
                'sort_order'  => $task->sort_order,
                'company_id'  => $companyId,
            ]);
        }

        return redirect()->route('onboarding.templates.show', $copy->uuid);
    }

    public function update(Request $request, OnboardingTemplate $template)
    {
        $this->refuseIfGlobal($template);
        $validated = $request->validate([
            'name'            => ['sometimes', 'required', 'string', 'max:150'],
            'description'     => ['nullable', 'string'],
            'department'      => ['nullable', 'string', 'max:100'],
            'job_title'       => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', 'string', 'in:permanent,contract,part_time'],
            'is_active'       => ['boolean'],
        ]);

        $template->update($validated);

        return back();
    }

    public function destroy(OnboardingTemplate $template)
    {
        $this->refuseIfGlobal($template);

        $activePlanCount = \App\Modules\HR\Onboarding\Models\OnboardingPlan::where('template_id', $template->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        if ($activePlanCount > 0) {
            throw ValidationException::withMessages([
                'template' => 'This template cannot be deleted because it is used by ' . $activePlanCount . ' active onboarding plan(s).',
            ]);
        }

        $template->delete();

        return redirect()->route('onboarding.templates.index');
    }

    public function storeTask(Request $request, OnboardingTemplate $template)
    {
        $this->refuseIfGlobal($template);

        $validated = $request->validate([
            'type'        => ['required', 'string', 'in:meeting,training,reading,system_setup,introduction,policy_acknowledgement'],
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'day_offset'  => ['nullable', 'integer', 'min:0', 'max:365'],
            'owner_type'  => ['nullable', 'string', 'in:hr,it,manager,buddy'],
        ]);

        $maxOrder = $template->templateTasks()->max('sort_order') ?? -1;

        OnboardingTemplateTask::create([
            'onboarding_template_id' => $template->id,
            'type'                   => $validated['type'],
            'title'                  => $validated['title'],
            'description'            => $validated['description'] ?? null,
            'day_offset'             => $validated['day_offset'] ?? 0,
            'owner_type'             => $validated['owner_type'] ?? null,
            'sort_order'             => $maxOrder + 1,
        ]);

        return redirect()->route('onboarding.templates.show', $template->uuid);
    }

    public function destroyTask(OnboardingTemplate $template, OnboardingTemplateTask $task)
    {
        $this->refuseIfGlobal($template);

        abort_unless($task->onboarding_template_id === $template->id, 404);

        $task->delete();

        return redirect()->route('onboarding.templates.show', $template->uuid);
    }
}
