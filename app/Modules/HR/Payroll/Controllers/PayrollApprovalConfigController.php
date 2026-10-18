<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollApprovalConfig;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the configurable payroll approval chain (settings). Mirrors the Fleet
 * booking approval configuration: ordered steps, each routed to a role or a
 * specific employee. Scoped to the current tenant.
 */
class PayrollApprovalConfigController extends Controller
{
    private function companyId(): ?int
    {
        return app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;
    }

    public function index(): Response
    {
        $cid = $this->companyId();

        $steps = PayrollApprovalConfig::when($cid, fn ($q) => $q->where('company_id', $cid))
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'payroll_approval_configs.employee_id')
            ->orderBy('step_number')
            ->get([
                'payroll_approval_configs.*',
                DB::raw("TRIM(CONCAT(p.first_name, ' ', COALESCE(p.surname, ''))) as employee_name"),
            ]);

        return Inertia::render('Modules/HR/Payroll/Settings/ApprovalSteps', [
            'steps' => $steps,
            'roles' => DB::table('roles')->orderBy('name')->pluck('name'),
            'employees' => DB::table('users as u')
                ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
                ->when($cid, fn ($q) => $q->where('u.company_id', $cid))
                ->where('u.status', 1)
                ->orderByRaw("COALESCE(p.first_name, u.email)")
                ->get([
                    'u.id',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', COALESCE(p.surname, ''))), ''), u.email) as name"),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        PayrollApprovalConfig::create($data);

        return back()->with('success', 'Approval step added.');
    }

    public function update(Request $request, PayrollApprovalConfig $config): RedirectResponse
    {
        $config->update($this->validated($request));

        return back()->with('success', 'Approval step updated.');
    }

    public function destroy(PayrollApprovalConfig $config): RedirectResponse
    {
        $config->delete();

        return back()->with('success', 'Approval step removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'step_number'   => ['required', 'integer', 'min:1', 'max:20'],
            'name'          => ['required', 'string', 'max:120'],
            'approver_type' => ['required', 'in:role,employee'],
            'role_name'     => ['nullable', 'required_if:approver_type,role', 'string', 'max:80'],
            'employee_id'   => ['nullable', 'required_if:approver_type,employee', 'integer', 'exists:users,id'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        // Clear the unused approver field so a role step has no stray employee and vice-versa.
        if ($data['approver_type'] === 'role') {
            $data['employee_id'] = null;
        } else {
            $data['role_name'] = null;
        }

        return $data;
    }
}
