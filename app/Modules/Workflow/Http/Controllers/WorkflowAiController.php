<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\WorkflowAccess;
use App\Modules\Workflow\Services\WorkflowAI;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** AI assistance (PRS §15). Drafts stay drafts; summaries and answers respect access and data settings. */
class WorkflowAiController extends WorkflowController
{
    public function __construct(private readonly WorkflowAI $ai, private readonly WorkflowAudit $audit)
    {
    }

    public function draft(Request $request, DefinitionService $definitions): RedirectResponse
    {
        $this->allow(WorkflowPermissions::DESIGN, 'ai.draft');
        $d = $request->validate(['description' => ['required', 'string', 'min:15', 'max:4000'], 'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', \Illuminate\Validation\Rule::in(app(WorkflowSettings::class)->categories())]]);
        $draft = $this->ai->draftGraph($d['description']);
        $definition = $definitions->create(['name' => $d['name'], 'category' => $d['category'] ?? null, 'description' => $d['description'], 'process_owner_id' => $request->user()->id],
            $draft['graph'], 'ai');
        $definitions->saveDraft($definition, $draft['graph'], 'Drafted ' . ($draft['source'] === 'ai' ? 'by AI' : 'from the description') . ': ' . mb_substr($d['description'], 0, 300));
        $this->audit->as('ai', 'Workflow AI', fn () => $this->audit->record('ai.draft', $definition, ['definition_id' => $definition->id,
            'context' => ['source' => $draft['source'], 'notes' => $draft['notes'], 'nodes' => count($draft['graph']['nodes'])]]));

        return redirect()->route('workflow.definitions.designer', $definition->uuid)
            ->with('success', 'A draft is ready. It is not active until someone authorised publishes it.')
            ->with('info', implode(' ', $draft['notes']) ?: null);
    }

    public function explain(WorkflowDefinition $definition): JsonResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        abort_unless($this->can(WorkflowPermissions::DESIGN) || $this->can(WorkflowPermissions::PUBLISH) || $this->can(WorkflowPermissions::MANAGE), 403);
        $version = $definition->workingVersion() ?? $definition->currentVersion;

        return response()->json($this->ai->explain($version));
    }

    public function summarise(Request $request, WorkflowTask $task, WorkflowAccess $access): JsonResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $instance = $task->instance()->with('definition')->firstOrFail();
        if (! $access->canView($request->user(), $instance) && ! $access->canClaim($request->user(), $task)) {
            abort(403);
        }
        abort_if($instance->definition->is_confidential, 403, 'AI summaries are not available for confidential workflows.');

        return response()->json($this->ai->summarise($task));
    }

    public function suggestFields(Request $request): JsonResponse
    {
        $this->allow(WorkflowPermissions::DESIGN);
        $d = $request->validate(['purpose' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json($this->ai->suggestFields($d['purpose']));
    }

    public function ask(Request $request): JsonResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        abort_unless($this->can(WorkflowPermissions::REPORTS) || $this->can(WorkflowPermissions::MANAGE), 403);
        $d = $request->validate(['question' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json($this->ai->answer($request->user(), $d["question"]) + ['recommendations' => $this->ai->recommendations($request->user())]);
    }
}
