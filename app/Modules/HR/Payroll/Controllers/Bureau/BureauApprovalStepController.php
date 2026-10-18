<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauApprovalStep;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Services\Bureau\BureauApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a bureau says who signs a client's payroll, and in what order.
 *
 * One screen per client, plus a tenant-wide default for the clients that have not asked for anything
 * particular — which is most of them.
 */
class BureauApprovalStepController extends Controller
{
    public function __construct(private readonly BureauApprovalService $approvals)
    {
    }

    public function index(Request $request, ?BureauCompany $company = null): Response
    {
        if ($company) {
            abort_unless($company->accessibleBy(Auth::user()), 403);
        }

        $own = BureauApprovalStep::query()
            ->when($company, fn ($q) => $q->where('bureau_company_id', $company->id))
            ->when(! $company, fn ($q) => $q->whereNull('bureau_company_id'))
            ->orderBy('sequence')
            ->with('approver:id,email')
            ->get();

        return Inertia::render('Modules/HR/Payroll/Bureau/ApprovalSteps', [
            'company' => $company ? ['uuid' => $company->uuid, 'name' => $company->name] : null,
            'steps'   => $own->map(fn (BureauApprovalStep $s) => [
                'uuid' => $s->uuid, 'sequence' => $s->sequence, 'name' => $s->name,
                'approver_type' => $s->approver_type, 'role_name' => $s->role_name,
                'approver_id' => $s->approver_id, 'approver_label' => $s->approverLabel(),
                'allow_self_approval' => $s->allow_self_approval,
                'is_active' => $s->is_active, 'instructions' => $s->instructions,
            ]),
            // What this client actually uses right now — its own chain, or the default it inherits.
            'effective' => $this->approvals->stepsFor($company)->map(fn ($s) => [
                'sequence' => $s->sequence, 'name' => $s->name, 'approver_label' => $s->approverLabel(),
                'inherited' => $company !== null && $s->bureau_company_id === null,
            ]),
            'roles' => DB::table('roles')->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'people' => User::query()
                ->orderBy('email')
                ->limit(200)
                ->get(['id', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name ?: $u->email]),
            'clients' => BureauCompany::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
        ]);
    }

    public function store(Request $request, ?BureauCompany $company = null): RedirectResponse
    {
        if ($company) {
            abort_unless($company->accessibleBy(Auth::user()), 403);
        }

        $data = $this->validated($request);

        $data['sequence'] ??= ((int) BureauApprovalStep::query()
            ->when($company, fn ($q) => $q->where('bureau_company_id', $company->id))
            ->when(! $company, fn ($q) => $q->whereNull('bureau_company_id'))
            ->max('sequence')) + 1;

        BureauApprovalStep::create($data + [
            'bureau_company_id' => $company?->id,
            'is_active'         => true,
            'created_by'        => Auth::id(),
        ]);

        return back()->with('success', 'Approval step added.');
    }

    public function update(Request $request, BureauApprovalStep $step): RedirectResponse
    {
        $step->fill($this->validated($request, creating: false))->save();

        return back()->with('success', 'Approval step updated.');
    }

    /**
     * Remove a step.
     *
     * Decisions already recorded against it are kept — they name the step on the row itself, so the
     * history of a run that was signed under the old chain still reads correctly.
     */
    public function destroy(BureauApprovalStep $step): RedirectResponse
    {
        $step->delete();

        return back()->with('success', 'Approval step removed.');
    }

    public function installDefaults(Request $request, ?BureauCompany $company = null): RedirectResponse
    {
        if ($company) {
            abort_unless($company->accessibleBy(Auth::user()), 403);
        }

        $created = $this->approvals->installDefaults($company);

        return back()->with('success', $created > 0
            ? "{$created} step(s) added."
            : 'The default steps are already in place.');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $data = $request->validate([
            'sequence'            => ['nullable', 'integer', 'min:1', 'max:99'],
            'name'                => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'approver_type'       => [$creating ? 'required' : 'sometimes', 'in:role,user'],
            'role_name'           => ['nullable', 'string', 'max:120'],
            'approver_id'         => ['nullable', 'integer', 'exists:users,id'],
            'allow_self_approval' => ['boolean'],
            'is_active'           => ['boolean'],
            'instructions'        => ['nullable', 'string', 'max:255'],
        ]);

        // A step has to name somebody, or it is a gate that nothing can open.
        if (($data['approver_type'] ?? null) === 'user' && blank($data['approver_id'] ?? null)) {
            abort(422, 'Choose the person who signs this step.');
        }

        if (($data['approver_type'] ?? null) === 'role' && blank($data['role_name'] ?? null)) {
            abort(422, 'Choose the role that signs this step.');
        }

        return $data;
    }
}
