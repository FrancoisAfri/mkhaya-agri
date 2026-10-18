<?php
namespace App\Modules\Policies\Controllers;
use App\Http\Controllers\Controller;
use App\Modules\Policies\Actions\PolicyViolation\RecordViolationNoteAction;
use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\Policies\Requests\PolicyViolation\{UpdateViolationRequest,AddViolationNoteRequest};
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
class PolicyViolationController extends Controller {
    public function index(Request $request): Response {
        $policyId = null;
        
        // Resolve policy UUID to ID if provided
        if ($request->policy_uuid) {
            $policy = \App\Modules\Policies\Models\Policy::where('uuid', $request->policy_uuid)->first();
            $policyId = $policy?->id;
        }
        
        $violations = PolicyViolation::with([
                'policy:id,name','rule:id,name,severity','user:id,email','user.person:id,user_id,first_name,surname',
            ])
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->severity, fn($q) => $q->where('severity', $request->severity))
            ->when($policyId,          fn($q) => $q->where('policy_id', $policyId))
            ->when($request->user_id,  fn($q) => $q->where('user_id', $request->user_id))
            ->latest()
            ->paginate(25)
            ->withQueryString();
        return Inertia::render('Policies/Violations/Index', [
            'violations' => $violations,
            'filters'    => $request->only(['status','severity','policy_uuid','user_id']),
            'statuses'   => ['open','under_investigation','resolved','dismissed'],
            'severities' => ['low','medium','high','critical'],
        ]);
    }
    public function show(PolicyViolation $violation): Response {
        $violation->load([
            'policy','rule.conditions','clause',
            'notes.author:id,email','notes.author.person:id,user_id,first_name,surname',
            'user:id,email','user.person:id,user_id,first_name,surname',
            'investigator:id,email','investigator.person:id,user_id,first_name,surname',
        ]);
        return Inertia::render('Policies/Violations/Show', [
            'violation' => $violation,
        ]);
    }
    public function update(UpdateViolationRequest $request, PolicyViolation $violation): RedirectResponse {
        $payload = $request->validated();

        if (($payload['status'] ?? null) === 'resolved') {
            $payload['resolved_at'] = now();
        }

        $violation->update($payload);

        return back()->with('success', 'Violation updated.');
    }
    public function addNote(AddViolationNoteRequest $request, PolicyViolation $violation, RecordViolationNoteAction $action): RedirectResponse {
        $action->execute(
            $violation,
            $request->validated(),
            $request->user()->id,
            $request->hasFile('attachment') ? $request->file('attachment') : null,
        );
        return back()->with('success', 'Note added.');
    }
}
