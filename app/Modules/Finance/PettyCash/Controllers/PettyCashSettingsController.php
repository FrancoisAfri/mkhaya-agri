<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashPolicyViolation;
use App\Modules\Policies\Models\EventRuleMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PettyCashSettingsController extends Controller
{
    public function index(): Response
    {
        $rules = EventRuleMap::where('module', 'PettyCash')
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

        return Inertia::render('Modules/Finance/PettyCash/Settings/Index', [
            'rules' => $rules,
        ]);
    }

    public function violations(Request $request): Response
    {
        $violations = PettyCashPolicyViolation::with(['request', 'float', 'resolvedBy'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('severity'), fn($q) => $q->where('severity', $request->severity))
            ->orderByDesc('detected_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/Finance/PettyCash/Settings/Violations', [
            'violations' => $violations->through(fn($v) => [
                'id'          => $v->id,
                'uuid'        => $v->uuid,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'policy_rule' => $v->policy_rule,
                'status'      => $v->status,
                'reference'   => $v->request?->reference_number,
                'float_name'  => $v->float?->name,
                'resolved_by' => $v->resolvedBy?->name,
                'detected_at' => $v->detected_at->toISOString(),
                'resolved_at' => $v->resolved_at?->toISOString(),
            ]),
            'filters' => $request->only('status', 'severity'),
        ]);
    }

    public function resolveViolation(Request $request, PettyCashPolicyViolation $violation): RedirectResponse
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
