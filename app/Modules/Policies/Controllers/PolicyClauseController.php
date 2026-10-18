<?php
namespace App\Modules\Policies\Controllers;
use App\Http\Controllers\Controller;
use App\Modules\Policies\Actions\PolicyClause\ApproveClausesAction;
use App\Modules\Policies\Models\{Policy,PolicyClause};
use App\Modules\Policies\Requests\PolicyClause\{ApproveClauseRequest,UpdateClauseRequest};
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
class PolicyClauseController extends Controller {
    /**
     * Full clause review page — shows AI-extracted clauses awaiting approval.
     */
    public function index(Policy $policy): Response {
        $policy->load([
            'clauses' => fn($q) => $q->orderBy('sort_order')->orderBy('id'),
            'documents.uploadedBy',
        ]);
        return Inertia::render('Policies/Clauses/Index', [
            'policy'  => $policy,
            'clauses' => $policy->clauses,
            'documents' => $policy->documents,
            'types'   => ['obligation','prohibition','threshold','approval','general'],
        ]);
    }
    public function update(UpdateClauseRequest $request, Policy $policy, PolicyClause $clause): RedirectResponse {
        abort_unless($clause->policy_id === $policy->id, 403);
        $clause->update($request->validated());
        return back()->with('success', 'Clause updated.');
    }
    public function approve(ApproveClauseRequest $request, Policy $policy, ApproveClausesAction $action): RedirectResponse {
        $count = $action->execute(
            $policy->clauses()
                ->whereIn('id', $request->validated()['clause_ids'])
                ->pluck('id')
                ->all(),
            $request->user()->id
        );
        return back()->with('success', "{$count} clause(s) approved.");
    }
    public function destroy(Policy $policy, PolicyClause $clause): RedirectResponse {
        abort_unless($clause->policy_id === $policy->id, 403);
        $clause->delete();
        return back()->with('success', 'Clause removed.');
    }
}
