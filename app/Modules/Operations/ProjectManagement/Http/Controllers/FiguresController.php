<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\DerivationService;
use App\Modules\Operations\ProjectManagement\Support\Derivation\DerivationRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the numbers come from (PM-1202, PM-1300, PM-1301).
 *
 * The whole module rests on being able to answer "why does this task say 62%?" with a list of
 * events rather than an assurance. This page is that answer: every derived figure, what kind of
 * claim produced it, how confident the module is in it, and the exact evidence underneath.
 *
 * Overriding is deliberately awkward — it demands a reason and records who — because a number that
 * disagrees with the evidence and does not say why is worse than the evidence alone.
 */
class FiguresController extends Controller
{
    public function __construct(private readonly DerivationService $derivation)
    {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $tasks = Task::where('project_id', $project->id)->real()->get()->keyBy('id');

        $values = DerivedValue::where('subject_type', 'task')
            ->whereIn('subject_id', $tasks->keys())
            ->orderBy('subject_id')
            ->get();

        return Inertia::render('Modules/ProjectManagement/Projects/Figures', [
            'project' => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name],
            'figures' => $values->map(fn (DerivedValue $v) => [
                'id'         => $v->uuid,
                'task'       => $tasks->get($v->subject_id)?->name,
                'task_code'  => $tasks->get($v->subject_id)?->code,
                'metric'     => $v->metric,
                'value'      => $v->value_numeric === null ? null : (float) $v->value_numeric,
                'text'       => $v->value_text,
                'confidence' => (float) $v->confidence,
                'version'    => $v->method_version,
                'count'      => (int) $v->evidence_count,
                'computed_at' => $v->computed_at?->toDateTimeString(),
                'overridden' => (bool) $v->is_overridden,
                'reason'     => $v->override_reason,
            ])->values()->all(),
            // The rules themselves, so the precedence can be read rather than taken on trust.
            //
            // `because_key` rather than `because`: the English sentence in DerivationRules is the
            // developer's note about why a rule exists, and this page is read by a quantity
            // surveyor arguing about a payment certificate — in their language. The key is built
            // from the rule's strongest event, which is stable across reordering in a way an index
            // would not be. `because` still travels as the fallback for a locale that has not
            // caught up yet, so a missing translation degrades to English rather than to blank.
            'rules'   => collect(DerivationRules::RULES)->map(fn (array $rules, string $metric) => array_map(fn (array $r) => [
                'kind'        => $r['kind'],
                'events'      => $r['events'],
                'confidence'  => $r['confidence'],
                'because'     => $r['because'],
                // Metric *and* event: `quantity.installed` feeds both progress and quantity, and
                // says something different in each — one key for both would put the wrong
                // explanation next to one of them.
                'because_key' => $metric . '_' . str_replace(['.', '*'], ['_', 'any'], $r['events'][0]),
            ], $rules))->all(),
            'version' => DerivationRules::VERSION,
            'metrics' => DerivationRules::metrics(),
            'can'     => ['override' => $request->user()->can('update', $project)],
        ]);
    }

    /** The events behind one figure, in the order they happened. */
    public function show(Project $project, string $uuid): Response
    {
        $this->authorize('view', $project);

        $value = $this->figure($project, $uuid);

        return Inertia::render('Modules/ProjectManagement/Projects/Figure', [
            'project' => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name],
            'figure'  => [
                'id'         => $value->uuid,
                'metric'     => $value->metric,
                'value'      => $value->value_numeric === null ? null : (float) $value->value_numeric,
                'text'       => $value->value_text,
                'confidence' => (float) $value->confidence,
                'version'    => $value->method_version,
                'overridden' => (bool) $value->is_overridden,
                'reason'     => $value->override_reason,
                'computed_at' => $value->computed_at?->toDateTimeString(),
            ],
            'evidence' => $this->derivation->evidenceBehind($value)->map(fn (EvidenceEvent $e) => [
                'id'          => $e->uuid,
                'type'        => $e->event_type,
                'source'      => $e->source_module,
                'occurred_at' => $e->occurred_at?->toDateTimeString(),
                'quantity'    => $e->quantity === null ? null : (float) $e->quantity,
                'unit'        => $e->unit,
                'amount'      => $e->amount === null ? null : (float) $e->amount,
                'currency'    => $e->currency,
                'confidence'  => (float) $e->confidence,
                'payload'     => $e->payload,
            ])->all(),
        ]);
    }

    /** Recompute this project's figures now, rather than waiting for tonight. */
    public function recalculate(Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $result = $this->derivation->deriveProject($project);

        return back()->with('success', __('project_management.flash.figures_recalculated', [
            'figures' => $result['figures'],
            'tasks'   => $result['tasks'],
        ]));
    }

    public function override(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $value = $this->figure($project, $uuid);

        $data = $request->validate([
            'value'  => ['required', 'numeric'],
            // Length floor as well as a ceiling: "n/a" is not a reason, and this field exists to
            // make someone account for a number the evidence does not support.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->derivation->override($value, (float) $data['value'], $data['reason']);

        return back()->with('success', __('project_management.flash.figure_overridden'));
    }

    public function clearOverride(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->derivation->clearOverride($this->figure($project, $uuid));

        return back()->with('success', __('project_management.flash.figure_override_cleared'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Scoped through the project, so a figure uuid from another job is a 404. */
    private function figure(Project $project, string $uuid): DerivedValue
    {
        $taskIds = Task::where('project_id', $project->id)->pluck('id');

        return DerivedValue::where('uuid', $uuid)
            ->where('subject_type', 'task')
            ->whereIn('subject_id', $taskIds)
            ->firstOrFail();
    }
}
