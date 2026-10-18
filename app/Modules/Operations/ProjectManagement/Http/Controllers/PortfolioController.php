<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Portfolio;
use App\Modules\Operations\ProjectManagement\Models\Programme;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectTemplate;
use App\Modules\Operations\ProjectManagement\Models\StageGate;
use App\Modules\Operations\ProjectManagement\Services\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portfolios, programmes, templates and stage gates (§2, §3).
 *
 * The portfolio page is a *rollup*, not another project list: totals per portfolio and programme,
 * with the projects underneath. A tenant using neither sees an empty page that explains what they
 * are for rather than a broken one.
 */
class PortfolioController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $canCost = $request->user()->can('create', Project::class);

        // One query per level rather than per row: a portfolio page on a tenant with eighty
        // projects would otherwise be eighty queries deep before it rendered anything.
        $projects = Project::query()->visibleTo($request->user())
            ->get(['id', 'uuid', 'code', 'name', 'status', 'stage', 'budget_amount', 'portfolio_id', 'program_id']);

        $byPortfolio = $projects->groupBy('portfolio_id');
        $byProgramme = $projects->groupBy('program_id');

        $shape = fn ($p) => [
            'id' => $p->uuid, 'code' => $p->code, 'name' => $p->name,
            'status' => $p->status, 'stage' => $p->stage,
            'budget' => $canCost && $p->budget_amount !== null ? (float) $p->budget_amount : null,
        ];

        return Inertia::render('Modules/ProjectManagement/Portfolio/Index', [
            'portfolios' => Portfolio::with('programmes')->orderBy('name')->get()
                ->map(function (Portfolio $pf) use ($byPortfolio, $byProgramme, $shape, $canCost) {
                    // Only the ones hung straight off the portfolio. `$byPortfolio` contains the
                    // programmes' projects too — a project in a programme carries both keys — so
                    // summing it whole and then adding the programme totals counts them twice.
                    $direct = ($byPortfolio[$pf->id] ?? collect())->whereNull('program_id');

                    $programmes = $pf->programmes->map(function (Programme $pg) use ($byProgramme, $shape, $canCost) {
                        $rows = $byProgramme[$pg->id] ?? collect();

                        return [
                            'id'       => $pg->uuid,
                            'code'     => $pg->code,
                            'name'     => $pg->name,
                            'outcome'  => $pg->outcome,
                            'budget'   => $canCost && $pg->budget_amount !== null ? (float) $pg->budget_amount : null,
                            'projects' => $rows->map($shape)->values()->all(),
                            'total'    => $canCost ? (float) $rows->sum('budget_amount') : null,
                        ];
                    })->values()->all();

                    return [
                        'id'         => $pf->uuid,
                        'code'       => $pf->code,
                        'name'       => $pf->name,
                        'description' => $pf->description,
                        'budget'     => $canCost && $pf->budget_amount !== null ? (float) $pf->budget_amount : null,
                        'programmes' => $programmes,
                        'projects'   => $direct->map($shape)->values()->all(),
                        'total'      => $canCost
                            ? (float) $direct->sum('budget_amount') + array_sum(array_column($programmes, 'total'))
                            : null,
                    ];
                })->all(),

            'unassigned' => $projects->whereNull('portfolio_id')->whereNull('program_id')->map($shape)->values()->all(),
            'programmes' => Programme::orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Programme $p) => ['id' => $p->uuid, 'name' => $p->name])->all(),
            'can' => ['manage' => $request->user()->can('manageSettings', Project::class), 'costs' => $canCost],
        ]);
    }

    public function storePortfolio(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        Portfolio::create($request->validate([
            'code'          => ['nullable', 'string', 'max:30'],
            'name'          => ['required', 'string', 'max:190'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'currency'      => ['nullable', 'string', 'size:3'],
        ]));

        return back()->with('success', __('project_management.flash.portfolio_added'));
    }

    public function storeProgramme(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $data = $request->validate([
            'portfolio_id'  => ['nullable', 'uuid'],
            'code'          => ['nullable', 'string', 'max:30'],
            'name'          => ['required', 'string', 'max:190'],
            'outcome'       => ['nullable', 'string', 'max:500'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'starts_on'     => ['nullable', 'date'],
            'ends_on'       => ['nullable', 'date', 'after_or_equal:starts_on'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['portfolio_id'] = ! empty($data['portfolio_id'])
            ? Portfolio::where('uuid', $data['portfolio_id'])->value('id')
            : null;

        Programme::create($data);

        return back()->with('success', __('project_management.flash.programme_added'));
    }

    /** Move a project into (or out of) the hierarchy. */
    public function assign(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'portfolio_id' => ['nullable', 'uuid'],
            'programme_id' => ['nullable', 'uuid'],
        ]);

        $programme = ! empty($data['programme_id'])
            ? Programme::where('uuid', $data['programme_id'])->first()
            : null;

        $project->update([
            // A project in a programme belongs to that programme's portfolio, whatever the form
            // said. Two different answers to "which portfolio" is how a rollup stops adding up.
            'portfolio_id' => $programme?->portfolio_id
                ?? (! empty($data['portfolio_id']) ? Portfolio::where('uuid', $data['portfolio_id'])->value('id') : null),
            'program_id'   => $programme?->id,
        ]);

        return back()->with('success', __('project_management.flash.project_assigned'));
    }

    // ── Templates ───────────────────────────────────────────────────────────

    public function templates(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        return Inertia::render('Modules/ProjectManagement/Setup/Templates', [
            'templates' => ProjectTemplate::orderBy('industry')->orderBy('name')->get()
                ->map(fn (ProjectTemplate $t) => [
                    'id'          => $t->uuid,
                    'name'        => $t->name,
                    'industry'    => $t->industry,
                    'sub_type'    => $t->sub_type,
                    'description' => $t->description,
                    'is_builtin'  => $t->is_builtin,
                    'is_active'   => $t->is_active,
                    'times_used'  => $t->times_used,
                    // A count, not the payload: nobody reads forty task definitions in a list, and
                    // sending them makes the page heavy for a number.
                    'counts'      => [
                        'wbs'   => count($t->section('wbs')),
                        'tasks' => count($t->section('tasks')),
                        'gates' => count($t->section('gates')),
                        'risks' => count($t->section('risks')),
                    ],
                ])->all(),
            'industries' => ProjectTemplate::INDUSTRIES,
            'projects'   => Project::query()->visibleTo($request->user())->orderBy('name')
                ->limit(200)->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => $p->code . ' · ' . $p->name])->all(),
            'can' => ['manage' => $request->user()->can('manageSettings', Project::class)],
        ]);
    }

    /** Lay a template's plan over an existing project. */
    public function applyTemplate(Request $request, Project $project, TemplateService $templates): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['template_id' => ['required', 'uuid']]);

        $template = ProjectTemplate::where('uuid', $data['template_id'])->usable()->firstOrFail();

        $counts = $templates->apply($project, $template);

        return back()->with('success', __('project_management.flash.template_applied', $counts));
    }

    /** Turn a project that went well into something the next one can start from. */
    public function captureTemplate(Request $request, TemplateService $templates): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $data = $request->validate([
            'project_id'  => ['required', 'uuid'],
            'name'        => ['required', 'string', 'max:190'],
            'industry'    => ['required', Rule::in(ProjectTemplate::INDUSTRIES)],
            'sub_type'    => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = Project::where('uuid', $data['project_id'])->firstOrFail();

        $this->authorize('view', $project);

        $templates->captureFrom($project, $data);

        return back()->with('success', __('project_management.flash.template_captured'));
    }

    public function destroyTemplate(string $uuid): RedirectResponse
    {
        $this->authorize('manageSettings', Project::class);

        $template = ProjectTemplate::where('uuid', $uuid)->firstOrFail();

        // A shipped template is not this tenant's to delete; deactivating it hides it from the
        // picker and survives the next upgrade, which a delete would not.
        $template->is_builtin
            ? $template->update(['is_active' => false])
            : $template->delete();

        return back()->with('success', __('project_management.flash.template_removed'));
    }

    // ── Stage gates ─────────────────────────────────────────────────────────

    public function storeGate(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'stage'    => ['required', Rule::in(Project::STAGES)],
            'name'     => ['required', 'string', 'max:190'],
            'criteria' => ['nullable', 'string', 'max:2000'],
        ]);

        StageGate::create([
            ...$data,
            'project_id' => $project->id,
            'position'   => (int) StageGate::where('project_id', $project->id)->max('position') + 1,
        ]);

        return back()->with('success', __('project_management.flash.gate_added'));
    }

    /**
     * Decide a gate.
     *
     * `waived` is offered alongside pass and fail on purpose. Gates do get skipped under commercial
     * pressure, and a system that offers only two outcomes gets the gate marked "passed" instead —
     * which destroys the one record that mattered.
     */
    public function decideGate(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('changeStatus', $project);

        $data = $request->validate([
            'status' => ['required', Rule::in(['passed', 'failed', 'waived'])],
            'note'   => ['nullable', 'string', 'max:2000'],
        ]);

        // A waived gate has to say why. "We skipped it" without a reason is the record being
        // deleted in slow motion.
        if ($data['status'] === 'waived' && trim((string) ($data['note'] ?? '')) === '') {
            return back()->withErrors(['note' => __('project_management.gates.waiver_needs_reason')]);
        }

        StageGate::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail()->update([
            'status'        => $data['status'],
            'decision_note' => $data['note'] ?? null,
            'decided_at'    => now(),
            'decided_by'    => $request->user()->id,
        ]);

        return back()->with('success', __('project_management.flash.gate_decided'));
    }

    public function destroyGate(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        StageGate::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', __('project_management.flash.gate_removed'));
    }
}
