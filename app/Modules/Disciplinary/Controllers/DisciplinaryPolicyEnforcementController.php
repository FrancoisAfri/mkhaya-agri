<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyEnforcementLog;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyException;
use App\Modules\Disciplinary\Models\DisciplinaryPolicyViolation;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryPolicyEnforcementController extends Controller
{
    use DisciplinaryAuthTrait;

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function index(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $violations = DisciplinaryPolicyViolation::with(['case', 'hearing', 'actor'])
            ->whereNull('is_resolved')
            ->orWhere('is_resolved', false)
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn($v) => $this->summariseViolation($v));

        $exceptions = DisciplinaryPolicyException::with(['violation', 'requestedBy', 'reviewedBy', 'case'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn($e) => $this->summariseException($e));

        $logs = DisciplinaryPolicyEnforcementLog::with(['case', 'actor'])
            ->orderByDesc('checked_at')
            ->paginate(50)
            ->through(fn($l) => $this->summariseLog($l));

        $stats = [
            'total_violations'   => DisciplinaryPolicyViolation::count(),
            'open_violations'    => DisciplinaryPolicyViolation::where('is_resolved', false)->orWhereNull('is_resolved')->count(),
            'blocked_actions'    => DisciplinaryPolicyViolation::where('action_taken', 'blocked')->count(),
            'pending_exceptions' => DisciplinaryPolicyException::where('status', 'pending')->count(),
            'checks_today'       => DisciplinaryPolicyEnforcementLog::whereDate('checked_at', today())->count(),
            'violations_by_type' => DisciplinaryPolicyViolation::selectRaw('violation_type, count(*) as total')
                ->groupBy('violation_type')
                ->pluck('total', 'violation_type'),
        ];

        return Inertia::render('Modules/Disciplinary/PolicyEnforcement/Index', [
            'violations' => $violations,
            'exceptions' => $exceptions,
            'logs'       => $logs,
            'stats'      => $stats,
        ]);
    }

    // ── Request exception for a violation ─────────────────────────────────────

    public function requestException(Request $request, DisciplinaryPolicyViolation $violation): RedirectResponse
    {
        $data = $request->validate([
            'reason'            => 'required|string|max:500',
            'justification'     => 'required|string',
            'risk_acknowledged' => 'nullable|string',
        ]);

        DisciplinaryPolicyException::create([
            'violation_id'      => $violation->id,
            'case_id'           => $violation->case_id,
            'hearing_id'        => $violation->hearing_id,
            'reason'            => $data['reason'],
            'justification'     => $data['justification'],
            'risk_acknowledged' => $data['risk_acknowledged'] ?? null,
            'requested_by'      => auth()->id(),
            'status'            => 'pending',
        ]);

        return back()->with('success', 'Exception request submitted for review.');
    }

    // ── Review an exception (admin only) ──────────────────────────────────────

    public function reviewException(Request $request, DisciplinaryPolicyException $exception): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'decision'       => 'required|in:approved,rejected',
            'review_note'    => 'nullable|string',
            'approved_until' => 'nullable|date|after:today',
        ]);

        $exception->update([
            'status'        => $data['decision'],
            'reviewed_by'   => auth()->id(),
            'reviewed_at'   => now(),
            'review_note'   => $data['review_note'] ?? null,
            'approved_until'=> $data['approved_until'] ?? null,
        ]);

        return back()->with('success', "Exception {$data['decision']}.");
    }

    // ── Resolve a violation ────────────────────────────────────────────────────

    public function resolveViolation(Request $request, DisciplinaryPolicyViolation $violation): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['resolution_note' => 'required|string']);

        $violation->update([
            'is_resolved'     => true,
            'resolved_at'     => now(),
            'resolved_by'     => auth()->id(),
            'resolution_note' => $data['resolution_note'],
        ]);

        return back()->with('success', 'Violation marked as resolved.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function summariseViolation(DisciplinaryPolicyViolation $v): array
    {
        return [
            'id'             => $v->id,
            'rule_name'      => $v->rule_name,
            'violation_type' => ucwords(str_replace('_', ' ', $v->violation_type)),
            'severity'       => $v->severity,
            'severity_color' => $v->severityColor(),
            'action_taken'   => $v->action_taken,
            'action_color'   => $v->actionColor(),
            'description'    => $v->description,
            'case_number'    => $v->case?->case_number,
            'case_id'        => $v->case_id,
            'hearing_id'     => $v->hearing_id,
            'actor'          => $this->personName($v->actor),
            'is_resolved'    => $v->is_resolved,
            'created_at'     => $v->created_at?->format('d M Y H:i'),
        ];
    }

    private function summariseException(DisciplinaryPolicyException $e): array
    {
        return [
            'id'             => $e->id,
            'violation_id'   => $e->violation_id,
            'rule_name'      => $e->violation?->rule_name,
            'case_id'        => $e->case_id,
            'case_number'    => $e->case?->case_number,
            'reason'         => $e->reason,
            'status'         => $e->status,
            'status_color'   => $e->statusColor(),
            'requested_by'   => $this->personName($e->requestedBy),
            'reviewed_by'    => $this->personName($e->reviewedBy),
            'approved_until' => $e->approved_until?->format('d M Y'),
            'created_at'     => $e->created_at?->format('d M Y H:i'),
        ];
    }

    private function summariseLog(DisciplinaryPolicyEnforcementLog $l): array
    {
        return [
            'id'                  => $l->id,
            'action_checked'      => $l->action_checked,
            'rules_evaluated'     => $l->rules_evaluated,
            'violations_found'    => $l->violations_found,
            'was_blocked'         => $l->was_blocked,
            'required_escalation' => $l->required_escalation,
            'passed_clean'        => $l->passed_clean,
            'status_label'        => $l->statusLabel(),
            'status_color'        => $l->statusColor(),
            'case_number'         => $l->case?->case_number,
            'case_id'             => $l->case_id,
            'actor'               => $this->personName($l->actor),
            'checked_at'          => $l->checked_at?->format('d M Y H:i'),
        ];
    }

    private function personName($user): string
    {
        if (! $user) return '—';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? '—');
    }
}
