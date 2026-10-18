<?php
namespace App\Modules\Policies\Controllers;
use App\Http\Controllers\Controller;
use App\Modules\Policies\Actions\PolicyException\{CreateExceptionAction,DecideExceptionAction};
use App\Modules\Policies\Models\PolicyException;
use App\Modules\Policies\Requests\PolicyException\{StoreExceptionRequest,DecideExceptionRequest};
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
class PolicyExceptionController extends Controller {
    public function index(Request $request): Response {
        $exceptions = PolicyException::with([
                'policy:id,name','rule:id,name',
                'requestedBy:id,email','requestedBy.person:id,user_id,first_name,surname',
                'approvedBy:id,email','approvedBy.person:id,user_id,first_name,surname',
            ])
            ->when($request->status,    fn($q) => $q->where('status', $request->status))
            ->when($request->policy_id, fn($q) => $q->where('policy_id', $request->policy_id))
            ->latest()
            ->paginate(20)
            ->withQueryString();
        return Inertia::render('Policies/Exceptions/Index', [
            'exceptions' => $exceptions,
            'filters'    => $request->only(['status','policy_id']),
            'statuses'   => ['pending','approved','rejected','expired','revoked'],
        ]);
    }
    public function store(StoreExceptionRequest $request, CreateExceptionAction $action): RedirectResponse {
        $action->execute($request->validated(), $request->user()->id);
        return redirect()->route('policy-exceptions.index')
            ->with('success', 'Exception request submitted.');
    }
    public function show(PolicyException $exception): Response {
        $exception->load(['policy','rule','requestedBy:id,email','requestedBy.person:id,user_id,first_name,surname','approvedBy:id,email','approvedBy.person:id,user_id,first_name,surname']);
        return Inertia::render('Policies/Exceptions/Show', [
            'exception' => $exception,
        ]);
    }
    public function decide(DecideExceptionRequest $request, PolicyException $exception, DecideExceptionAction $action): RedirectResponse {
        abort_unless($exception->status === 'pending', 422, 'Exception already decided.');
        $action->execute(
            $exception,
            $request->decision,
            $request->user()->id,
            $request->decision_notes,
        );
        $label = ucfirst($request->decision);
        return back()->with('success', "Exception {$label}.");
    }
    public function revoke(PolicyException $exception): RedirectResponse {
        abort_unless($exception->status === 'approved', 422, 'Only approved exceptions can be revoked.');
        $exception->update(['status' => 'revoked']);
        return back()->with('success', 'Exception revoked.');
    }
}
