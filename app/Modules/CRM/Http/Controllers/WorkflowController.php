<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmWorkflow;
use App\Modules\CRM\Models\CrmWorkflowRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Automation & Workflow builder (spec section 3).
 */
class WorkflowController extends Controller
{
    public function index(): Response
    {
        $workflows = CrmWorkflow::withCount('runs')->orderBy('run_order')->latest()->get()
            ->map(fn ($w) => [
                'id'            => $w->id,
                'uuid'          => $w->uuid,
                'name'          => $w->name,
                'description'   => $w->description,
                'trigger'       => $w->trigger,
                'trigger_label' => CrmWorkflow::TRIGGERS[$w->trigger] ?? $w->trigger,
                'trigger_config'=> $w->trigger_config ?? [],
                'match_type'    => $w->match_type,
                'conditions'    => $w->conditions ?? [],
                'actions'       => $w->actions ?? [],
                'steps'         => $w->steps ?? [],
                'graph'         => $w->graph ?? null,
                'is_active'     => $w->is_active,
                'run_count'     => $w->run_count,
                'runs'          => $w->runs_count,
                'last_run_at'   => $w->last_run_at?->diffForHumans(),
            ]);

        $recentRuns = CrmWorkflowRun::with('workflow:id,name')->latest()->limit(25)->get()
            ->map(fn ($r) => [
                'id'       => $r->id,
                'workflow' => $r->workflow?->name,
                'entity'   => $r->entity_type . ' #' . $r->entity_id,
                'status'   => $r->status,
                'detail'   => $r->detail,
                'when'     => $r->created_at?->diffForHumans(),
            ]);

        // Stages (for the "moved into stage X" trigger config).
        $stages = CrmPipeline::with('stages:id,pipeline_id,name')->get()
            ->flatMap(fn ($p) => $p->stages->map(fn ($s) => ['id' => $s->id, 'name' => $p->name . ' › ' . $s->name]));

        return Inertia::render('Modules/CRM/Automations', [
            'workflows'   => $workflows,
            'recentRuns'  => $recentRuns,
            'triggers'    => CrmWorkflow::TRIGGERS,
            'actionTypes' => CrmWorkflow::ACTION_TYPES,
            'aiActions'   => CrmWorkflow::AI_ACTIONS,
            'dateFields'  => CrmWorkflow::DATE_FIELDS,
            'stepTypes'   => CrmWorkflow::STEP_TYPES,
            'templates'   => CrmWorkflow::TEMPLATES,
            'conditionFields' => CrmWorkflow::CONDITION_FIELDS,
            'triggerEntity'   => CrmWorkflow::TRIGGER_ENTITY,
            'stages'      => $stages,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['uuid']       = Str::uuid();
        $data['created_by'] = Auth::id();

        CrmWorkflow::create($data);

        return back()->with('success', 'Workflow created.');
    }

    public function update(Request $request, CrmWorkflow $workflow): RedirectResponse
    {
        $workflow->update($this->validateData($request));

        return back()->with('success', 'Workflow updated.');
    }

    public function toggle(CrmWorkflow $workflow): RedirectResponse
    {
        $workflow->update(['is_active' => ! $workflow->is_active]);

        return back()->with('success', $workflow->is_active ? 'Workflow activated.' : 'Workflow paused.');
    }

    public function destroy(CrmWorkflow $workflow): RedirectResponse
    {
        $workflow->delete();

        return back()->with('success', 'Workflow deleted.');
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name'                 => 'required|string|max:160',
            'description'          => 'nullable|string|max:400',
            'trigger'              => 'required|in:' . implode(',', array_keys(CrmWorkflow::TRIGGERS)),
            'trigger_config'       => 'nullable|array',
            'match_type'           => 'required|in:all,any',
            'conditions'           => 'nullable|array',
            'conditions.*.field'   => 'required_with:conditions|string',
            'conditions.*.operator'=> 'required_with:conditions|string',
            'actions'              => 'nullable|array',
            'actions.*.type'       => 'required_with:actions|in:' . implode(',', array_keys(CrmWorkflow::ACTION_TYPES)),
            'steps'                => 'nullable|array',
            'steps.*.type'         => 'required_with:steps|in:' . implode(',', array_keys(CrmWorkflow::STEP_TYPES)),
            'graph'                => 'nullable|array',
            'is_active'            => 'boolean',
            'run_order'            => 'nullable|integer|min:0',
        ]);

        $data['run_order'] = $data['run_order'] ?? 0;

        return $data;
    }
}
