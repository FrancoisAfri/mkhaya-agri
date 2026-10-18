<?php

namespace App\Modules\Finance\StaffLoan\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\StaffLoan\Models\StaffLoanPolicyViolation;
use App\Modules\Finance\StaffLoan\Models\StaffLoanType;
use App\Modules\Policies\Models\EventRuleMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffLoanSettingsController extends Controller
{
    public function index(): Response
    {
        $rules = EventRuleMap::where('module', 'StaffLoan')
            ->where('active', true)
            ->with(['rule.conditions'])
            ->get()
            ->map(fn($m) => [
                'id'               => $m->rule->id,
                'name'             => $m->rule->name,
                'description'      => $m->rule->description,
                'severity'         => $m->rule->severity,
                'action_on_breach' => $m->rule->action_on_breach,
                'action_pattern'   => $m->action_pattern,
                'active'           => $m->rule->active,
            ]);

        $loanTypes = StaffLoanType::all();

        return Inertia::render('Modules/Finance/StaffLoan/Settings/Index', [
            'rules'     => $rules,
            'loanTypes' => $loanTypes,
        ]);
    }

    public function storeLoanType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                          => 'required|string|max:255',
            'description'                   => 'nullable|string',
            'min_amount'                    => 'required|numeric|min:0',
            'max_amount'                    => 'required|numeric|gt:min_amount',
            'min_term_months'               => 'required|integer|min:1',
            'max_term_months'               => 'required|integer|gte:min_term_months',
            'interest_rate'                 => 'required|numeric|min:0',
            'interest_type'                 => 'required|in:none,fixed,reducing_balance',
            'requires_permanent_status'     => 'boolean',
            'requires_probation_completion' => 'boolean',
            'executive_approval_threshold'  => 'nullable|numeric|min:0',
            'is_active'                     => 'boolean',
        ]);

        StaffLoanType::create($data);

        return back()->with('success', 'Loan type created.');
    }

    public function violations(Request $request): Response
    {
        $violations = StaffLoanPolicyViolation::with(['employee', 'loan', 'advance', 'resolvedBy'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('severity'), fn($q) => $q->where('severity', $request->severity))
            ->orderByDesc('detected_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/Finance/StaffLoan/Settings/Violations', [
            'violations' => $violations->through(fn($v) => [
                'id'          => $v->id,
                'uuid'        => $v->uuid,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'policy_rule' => $v->policy_rule,
                'status'      => $v->status,
                'employee'    => $v->employee?->name,
                'loan_ref'    => $v->loan?->reference_number,
                'advance_ref' => $v->advance?->reference_number,
                'resolved_by' => $v->resolvedBy?->name,
                'detected_at' => $v->detected_at->toISOString(),
                'resolved_at' => $v->resolved_at?->toISOString(),
            ]),
            'filters' => $request->only('status', 'severity'),
        ]);
    }

    public function resolveViolation(Request $request, StaffLoanPolicyViolation $violation): RedirectResponse
    {
        $data = $request->validate(['resolution_notes' => 'required|string|max:500']);

        $violation->update([
            'status'           => 'resolved',
            'resolved_by'      => $request->user()->id,
            'resolved_at'      => now(),
            'resolution_notes' => $data['resolution_notes'],
        ]);

        return back()->with('success', 'Violation resolved.');
    }
}
