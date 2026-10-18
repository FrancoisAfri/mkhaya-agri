<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryEscalation;
use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use App\Modules\Disciplinary\Models\DisciplinaryWarning;
use App\Modules\Disciplinary\Services\DisciplinaryPolicyGuard;
use App\Modules\Disciplinary\Services\ProgressiveDisciplineService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryProgressiveDisciplineController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(
        private readonly ProgressiveDisciplineService $service,
        private readonly DisciplinaryPolicyGuard      $policyGuard,
    ) {}

    // ── Warnings ─────────────────────────────────────────────────────────────

    public function indexWarnings(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $warnings = DisciplinaryWarning::with(['employee.person', 'case', 'issuedBy'])
            ->when($request->employee_id, fn($q, $id) => $q->where('employee_id', $id))
            ->when($request->type,        fn($q, $t)  => $q->where('warning_type', $t))
            ->when($request->expired !== null, fn($q)  => $q->where('is_expired', $request->boolean('expired')))
            ->orderByDesc('issued_date')
            ->paginate(25)
            ->through(fn($w) => $this->mapWarning($w));

        return Inertia::render('Modules/Disciplinary/ProgressiveDiscipline/Warnings', [
            'warnings'   => $warnings,
            'filters'    => $request->only('employee_id', 'type', 'expired'),
            'employees'  => $this->employeeList(),
            'open_cases' => $this->openCaseList(),
        ]);
    }

    public function storeWarning(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'        => 'required|exists:users,id',
            'warning_type'       => 'required|in:verbal,written,final_written',
            'description'        => 'required|string',
            'case_id'            => 'nullable|exists:disciplinary_cases,id',
            'hearing_id'         => 'nullable|exists:disciplinary_hearings,id',
            'incident_reference' => 'nullable|string|max:100',
        ]);

        if (!empty($data['case_id'])) {
            $case = \App\Modules\Disciplinary\Models\DisciplinaryCase::find($data['case_id']);
            if ($case) {
                $this->policyGuard->forWarningExpiry($case, $data['warning_type'], now(), auth()->id());
            }
        }

        $method = match ($data['warning_type']) {
            'verbal'        => 'issueVerbalWarning',
            'written'       => 'issueWrittenWarning',
            'final_written' => 'issueFinalWarning',
        };

        $warning = $this->service->$method(
            $data['employee_id'], auth()->id(), $data['description'],
            $data['case_id'] ?? null, $data['hearing_id'] ?? null,
            $data['incident_reference'] ?? null,
        );

        return redirect()->back()->with('success', "Warning issued: {$warning->warning_type}");
    }

    // ── Suspensions ───────────────────────────────────────────────────────────

    public function indexSuspensions(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $suspensions = DisciplinarySuspension::with(['employee.person', 'case', 'issuedBy'])
            ->when($request->employee_id, fn($q, $id) => $q->where('employee_id', $id))
            ->when($request->status,      fn($q, $s)  => $q->where('status', $s))
            ->orderByDesc('start_date')
            ->paginate(25)
            ->through(fn($s) => $this->mapSuspension($s));

        return Inertia::render('Modules/Disciplinary/ProgressiveDiscipline/Suspensions', [
            'suspensions' => $suspensions,
            'filters'     => $request->only('employee_id', 'status'),
            'employees'   => $this->employeeList(),
            'open_cases'  => $this->openCaseList(),
        ]);
    }

    public function storeSuspension(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'     => 'required|exists:users,id',
            'suspension_type' => 'required|in:paid,unpaid,precautionary',
            'reason'          => 'required|string',
            'start_date'      => 'required|date',
            'end_date'        => 'nullable|date|after:start_date',
            'case_id'         => 'nullable|exists:disciplinary_cases,id',
            'notes'           => 'nullable|string',
        ]);

        $days = !empty($data['end_date'])
            ? (int) Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date']))
            : null;

        $this->policyGuard->forSuspensionAction(
            $data['employee_id'], 'suspend', $days, auth()->id(), $data['case_id'] ?? null
        );

        $suspension = $this->service->issueSuspension(
            $data['employee_id'], auth()->id(),
            $data['suspension_type'], $data['reason'],
            $data['start_date'], $data['end_date'] ?? null,
            $data['case_id'] ?? null, $data['notes'] ?? null,
        );

        return redirect()->back()->with('success', "Suspension issued from {$suspension->start_date->format('d M Y')}");
    }

    public function endSuspension(Request $request, DisciplinarySuspension $suspension): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['actual_return_date' => 'required|date']);
        $this->service->endSuspension($suspension, $data['actual_return_date']);

        return redirect()->back()->with('success', 'Suspension ended.');
    }

    public function cancelSuspension(Request $request, DisciplinarySuspension $suspension): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        abort_if($suspension->status !== 'active', 422, 'Only active suspensions can be cancelled.');

        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $this->service->cancelSuspension($suspension, $data['reason']);

        return redirect()->back()->with('success', 'Suspension cancelled and employee access restored.');
    }

    public function extendSuspension(Request $request, DisciplinarySuspension $suspension): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        abort_if($suspension->status !== 'active', 422, 'Only active suspensions can be extended.');

        $data = $request->validate([
            'new_end_date' => 'required|date|after:' . ($suspension->end_date?->toDateString() ?? 'today'),
            'reason'       => 'required|string|max:500',
        ]);

        $this->service->extendSuspension($suspension, $data['new_end_date'], $data['reason']);

        return redirect()->back()->with('success', 'Suspension extended to ' . \Illuminate\Support\Carbon::parse($data['new_end_date'])->format('d M Y') . '.');
    }

    public function convertSuspension(Request $request, DisciplinarySuspension $suspension): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        abort_if($suspension->status !== 'active', 422, 'Only active suspensions can be converted.');

        $data = $request->validate([
            'new_type' => 'required|in:paid,unpaid,precautionary',
            'reason'   => 'required|string|max:500',
        ]);

        $this->service->convertSuspensionType($suspension, $data['new_type'], $data['reason']);

        return redirect()->back()->with('success', "Suspension type changed to {$data['new_type']}.");
    }

    public function addSuspensionNote(Request $request, DisciplinarySuspension $suspension): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data      = $request->validate(['note' => 'required|string|max:1000']);
        $actor     = auth()->user();
        $actorName = $actor?->person?->full_name ?? $actor?->email ?? 'System';
        $existing  = $suspension->notes ? $suspension->notes . "\n" : '';

        $suspension->update([
            'notes' => $existing . '[' . now()->format('d M Y H:i') . " — {$actorName}] " . $data['note'],
        ]);

        activity('disciplinary')
            ->causedBy($actor)
            ->performedOn($suspension)
            ->withProperties([
                'note'        => $data['note'],
                'employee_id' => $suspension->employee_id,
                'employee'    => $suspension->employee?->person?->full_name ?? $suspension->employee?->email,
                'case_id'     => $suspension->case_id,
            ])
            ->event('suspension_note_added')
            ->log("Note added to suspension for " . ($suspension->employee?->person?->full_name ?? $suspension->employee?->email));

        return redirect()->back()->with('success', 'Note added.');
    }

    // ── Escalations ───────────────────────────────────────────────────────────

    public function indexEscalations(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $escalations = DisciplinaryEscalation::with(['employee.person', 'case', 'triggeredBy', 'approvedBy'])
            ->when($request->pending, fn($q) => $q->pending())
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn($e) => [
                'id'                   => $e->id,
                'employee'             => $e->employee->person?->full_name ?? $e->employee->email,
                'employee_id'          => $e->employee_id,
                'from_level'           => $e->from_level,
                'to_level'             => $e->to_level,
                'reason'               => $e->reason,
                'ai_recommended'       => $e->ai_recommended,
                'ai_reasoning'         => $e->ai_reasoning,
                'prior_offences_count' => $e->prior_offences_count,
                'approved'             => $e->approved,
                'approved_by'          => $e->approvedBy?->person?->full_name,
                'approved_at'          => $e->approved_at?->format('d M Y'),
                'triggered_by'         => $e->triggeredBy?->person?->full_name,
                'created_at'           => $e->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Modules/Disciplinary/ProgressiveDiscipline/Escalations', [
            'escalations' => $escalations,
            'employees'   => $this->employeeList(),
            'open_cases'  => $this->openCaseList(),
        ]);
    }

    public function recommendEscalation(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id' => 'required|exists:users,id',
            'case_id'     => 'nullable|exists:disciplinary_cases,id',
        ]);

        if (!empty($data['case_id'])) {
            $case = \App\Modules\Disciplinary\Models\DisciplinaryCase::find($data['case_id']);
            if ($case) {
                $this->policyGuard->forProgressiveDiscipline($case, 'escalation', auth()->id());
            }
        }

        $escalation = $this->service->recommendDisciplineEscalation(
            $data['employee_id'], $data['case_id'] ?? null, auth()->id()
        );

        return redirect()->back()->with('success', "Escalation recommended: {$escalation->from_level} → {$escalation->to_level}");
    }

    public function approveEscalation(DisciplinaryEscalation $escalation): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $escalation->update([
            'approved'    => true,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Escalation approved.');
    }

    // ── Employee progressive history ──────────────────────────────────────────

    public function employeeHistory(int $employeeId): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $employee    = \App\Core\User\Models\User::with('person')->findOrFail($employeeId);
        $history     = $this->service->buildProgressiveHistory($employeeId);
        $warnings    = DisciplinaryWarning::forEmployee($employeeId)->with(['case', 'issuedBy'])->orderByDesc('issued_date')->get()->map(fn($w) => $this->mapWarning($w));
        $suspensions = DisciplinarySuspension::where('employee_id', $employeeId)->with(['case', 'issuedBy'])->orderByDesc('start_date')->get()->map(fn($s) => $this->mapSuspension($s));
        $escalations = DisciplinaryEscalation::where('employee_id', $employeeId)->with('case')->orderByDesc('created_at')->get()
            ->map(fn($e) => [
                'id'          => $e->id,
                'from_level'  => $e->from_level,
                'to_level'    => $e->to_level,
                'reason'      => $e->reason,
                'status'      => $e->status,
                'case_number' => $e->case?->case_number,
                'case_id'     => $e->case_id,
                'created_at'  => $e->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Modules/Disciplinary/ProgressiveDiscipline/EmployeeHistory', [
            'employee_id'   => $employeeId,
            'employee_name' => $employee->person?->full_name ?? $employee->email,
            'history'       => $history,
            'warnings'      => $warnings,
            'suspensions'   => $suspensions,
            'escalations'   => $escalations,
        ]);
    }

    // ── Map helpers ───────────────────────────────────────────────────────────

    private function mapWarning(DisciplinaryWarning $w): array
    {
        return [
            'id'                    => $w->id,
            'employee'              => $w->employee->person?->full_name ?? $w->employee->email,
            'employee_id'           => $w->employee_id,
            'warning_type'          => $w->warning_type,
            'issued_date'           => $w->issued_date?->format('d M Y'),
            'expiry_date'           => $w->expiry_date?->format('d M Y'),
            'is_expired'            => $w->is_expired,
            'employee_acknowledged' => $w->employee_acknowledged,
            'description'           => $w->description,
            'reason'                => $w->description,
            'case_number'           => $w->case?->case_number,
            'case_id'               => $w->case_id,
            'issued_by'             => $w->issuedBy?->person?->full_name,
        ];
    }

    private function mapSuspension(DisciplinarySuspension $s): array
    {
        return [
            'id'                   => $s->id,
            'employee'             => $s->employee->person?->full_name ?? $s->employee->email,
            'employee_id'          => $s->employee_id,
            'suspension_type'      => $s->suspension_type,
            'start_date'           => $s->start_date?->format('d M Y'),
            'end_date'             => $s->end_date?->format('d M Y'),
            'actual_return_date'   => $s->actual_return_date?->format('d M Y'),
            'suspension_days'      => $s->suspension_days,
            'status'               => $s->status,
            'reason'               => $s->reason,
            'case_number'          => $s->case?->case_number,
            'case_id'              => $s->case_id,
            'issued_by'            => $s->issuedBy?->person?->full_name,
            'issued_at'            => $s->created_at?->format('d M Y H:i'),
            'notes'                => $s->notes,
        ];
    }

    private function employeeList(): \Illuminate\Support\Collection
    {
        return User::join('hr_people as p', 'p.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email']);
    }

    private function openCaseList(): \Illuminate\Support\Collection
    {
        return DisciplinaryCase::where(function ($q) {
                $q->whereDoesntHave('status')
                  ->orWhereHas('status', fn($q2) => $q2->where('is_closed', false));
            })
            ->orderByDesc('created_at')
            ->get(['id', 'case_number', 'title'])
            ->map(fn($c) => ['id' => $c->id, 'label' => "{$c->case_number} — {$c->title}"]);
    }
}
