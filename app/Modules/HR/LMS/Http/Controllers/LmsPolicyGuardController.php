<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsPolicyEnforcementLog;
use App\Modules\HR\LMS\Models\LmsPolicyException;
use App\Modules\HR\LMS\Models\LmsPolicyViolation;
use App\Modules\HR\LMS\Services\LmsPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsPolicyGuardController extends Controller
{
    public function __construct(private readonly LmsPolicyGuard $guard) {}

    // ── Violations log ────────────────────────────────────────────────────────

    public function violations(Request $request): Response
    {
        $violations = LmsPolicyViolation::with(['actor.person', 'enrollment.course', 'resolvedBy.person'])
            ->when($request->violation_type, fn ($q) => $q->where('violation_type', $request->violation_type))
            ->when($request->action_taken,   fn ($q) => $q->where('action_taken', $request->action_taken))
            ->when($request->resolved === 'false' || !$request->filled('resolved'),
                fn ($q) => $q->where('is_resolved', false))
            ->when($request->resolved === 'true', fn ($q) => $q->where('is_resolved', true))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $exceptions = LmsPolicyException::with(['user.person', 'requestedBy.person', 'approvedBy.person', 'rule'])
            ->when($request->exception_status, fn ($q) => $q->where('status', $request->exception_status))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $logs = LmsPolicyEnforcementLog::with(['actor.person', 'enrollment.course'])
            ->orderByDesc('checked_at')
            ->limit(50)
            ->get()
            ->map(fn ($l) => [
                'id'                 => $l->id,
                'action_checked'     => $l->action_checked,
                'rules_evaluated'    => $l->rules_evaluated,
                'violations_found'   => $l->violations_found,
                'was_blocked'        => $l->was_blocked,
                'required_escalation'=> $l->required_escalation,
                'passed_clean'       => $l->passed_clean,
                'status_label'       => $l->statusLabel(),
                'status_color'       => $l->statusColor(),
                'actor_name'         => $l->actor?->person
                    ? "{$l->actor->person->first_name} {$l->actor->person->surname}"
                    : ($l->actor?->email ?? '—'),
                'checked_at'         => $l->checked_at?->toDateTimeString(),
            ]);

        return Inertia::render('Modules/LMS/Admin/LmsPolicyViolations', [
            'violations'      => $violations,
            'exceptions'      => $exceptions,
            'enforcementLogs' => $logs,
            'violationTypes'  => LmsPolicyViolation::VIOLATION_TYPES,
            'filters'         => $request->only('violation_type', 'action_taken', 'resolved', 'exception_status'),
        ]);
    }

    // ── Resolve a violation ───────────────────────────────────────────────────

    public function resolveViolation(Request $request, LmsPolicyViolation $violation): RedirectResponse
    {
        $request->validate(['resolution_note' => ['required', 'string']]);

        $violation->update([
            'is_resolved'     => true,
            'resolved_at'     => now(),
            'resolved_by'     => Auth::id(),
            'resolution_note' => $request->resolution_note,
        ]);

        return redirect()->back()->with('success', 'Violation resolved.');
    }

    // ── Exception management ──────────────────────────────────────────────────

    public function requestException(Request $request): RedirectResponse
    {
        $request->validate([
            'user_id'      => ['required', 'integer', 'exists:users,id'],
            'context_type' => ['required', 'string', 'in:enrollment,assignment,certificate,mandatory_training'],
            'context_id'   => ['nullable', 'integer'],
            'reason'       => ['required', 'string'],
            'rule_id'      => ['nullable', 'integer', 'exists:lms_compliance_rules,id'],
            'valid_until'  => ['nullable', 'date', 'after:today'],
        ]);

        $this->guard->requestTrainingPolicyException(
            userId:      $request->user_id,
            contextType: $request->context_type,
            contextId:   $request->context_id,
            reason:      $request->reason,
            ruleId:      $request->rule_id,
            requestedBy: Auth::id(),
            validUntil:  $request->valid_until,
        );

        return redirect()->back()->with('success', 'Exception request submitted.');
    }

    public function decideException(Request $request, LmsPolicyException $exception): RedirectResponse
    {
        $request->validate([
            'decision'       => ['required', 'in:approved,rejected'],
            'decision_notes' => ['nullable', 'string'],
        ]);

        $exception->update([
            'status'         => $request->decision,
            'approved_by'    => Auth::id(),
            'decided_at'     => now(),
            'decision_notes' => $request->decision_notes,
        ]);

        return redirect()->back()->with('success', 'Exception ' . $request->decision . '.');
    }
}
