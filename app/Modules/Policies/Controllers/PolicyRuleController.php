<?php
namespace App\Modules\Policies\Controllers;
use App\Http\Controllers\Controller;
use App\Modules\Policies\Actions\PolicyRule\{CreateRuleAction,ParseNaturalLanguageRuleAction};
use App\Modules\Policies\Models\{Policy,PolicyRule,NlRuleDraft};
use App\Modules\Policies\Requests\PolicyRule\{StoreRuleRequest,NaturalLanguageRuleRequest};
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
class PolicyRuleController extends Controller {
    public function index(Policy $policy): Response {
        $policy->load([
            'rules' => fn($q) => $q->with(['conditions','actions','eventMaps','clause'])->orderByDesc('id'),
            'clauses' => fn($q) => $q->orderBy('sort_order')->orderBy('id'),
            'documents',
        ]);

        $drafts = NlRuleDraft::query()
            ->where('policy_id', $policy->id)
            ->with(['clause', 'createdBy'])
            ->latest()
            ->get();

        return Inertia::render('Policies/Rules/Index', [
            'policy'     => $policy,
            'rules'      => $policy->rules,
            'clauses'    => $policy->clauses,
            'drafts'     => $drafts,
            'severities' => ['low','medium','high','critical'],
            'operators'  => ['eq','neq','gt','gte','lt','lte','in','not_in','between','contains'],
            'conditionTypes' => ['numeric','role','date','status','department','custom'],
            'actionTypes' => ['block','allow_with_warning','require_approval','send_notification','escalate_to_role','log_violation','trigger_workflow'],
        ]);
    }
    public function store(StoreRuleRequest $request, Policy $policy, CreateRuleAction $action): RedirectResponse {
        $action->execute($policy->id, $request->validated(), $request->user()->id);
        return redirect()->route('policies.rules.index', $policy)
            ->with('success', 'Rule created.');
    }
    public function toggle(Request $request, Policy $policy, PolicyRule $rule): RedirectResponse {
        abort_unless($rule->policy_id === $policy->id, 403);
        $rule->update(['active' => ! $rule->active]);
        $state = $rule->active ? 'activated' : 'deactivated';
        return back()->with('success', "Rule {$state}.");
    }
    public function destroy(Policy $policy, PolicyRule $rule): RedirectResponse {
        abort_unless($rule->policy_id === $policy->id, 403);
        $rule->delete();
        return back()->with('success', 'Rule deleted.');
    }
    /**
     * Step 1 — Parse NL input via AI, save to nl_rule_drafts, return for review.
     */
    public function parseNaturalLanguage(NaturalLanguageRuleRequest $request, ParseNaturalLanguageRuleAction $action): \Illuminate\Http\JsonResponse {
        try {
            $policy = Policy::query()
                ->where('uuid', $request->policy_uuid)
                ->firstOrFail();

            $draft = $action->execute(
                policyId: (int) $policy->id,
                input:    $request->input,
                userId:   (int) $request->user()->id,
                clauseId: $request->clause_id,
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to parse natural language rule input.',
            ], 422);
        }

        return response()->json(['draft' => $draft]);
    }
    /**
     * Step 2 — User confirms/edits the AI draft, convert to real rule.
     */
    public function confirmNlDraft(StoreRuleRequest $request, Policy $policy, NlRuleDraft $draft, CreateRuleAction $action): RedirectResponse {
        $rule = $action->execute($policy->id, $request->validated(), $request->user()->id);
        $draft->update(['status' => 'converted', 'converted_rule_id' => $rule->id]);
        return redirect()->route('policies.rules.index', $policy)
            ->with('success', 'Rule confirmed and saved.');
    }
}
