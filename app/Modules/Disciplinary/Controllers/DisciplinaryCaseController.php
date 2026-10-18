<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCategory;
use App\Modules\Disciplinary\Models\DisciplinaryOutcome;
use App\Modules\Disciplinary\Models\DisciplinarySanction;
use App\Modules\Disciplinary\Models\DisciplinaryStatus;
use App\Modules\Disciplinary\Notifications\DisciplinaryCaseAssignedNotification;
use App\Modules\Disciplinary\Services\DisciplinaryPolicyGuard;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DisciplinaryCaseController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(private DisciplinaryPolicyGuard $policyGuard) {}

    public function index(): Response
    {
        $cases = $this->scopedCaseQuery()
            ->with(['employee', 'category', 'status',
                'hearings' => fn($q) => $q->whereNotIn('status', ['cancelled', 'completed'])->orderBy('scheduled_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn($c) => [
                'id'            => $c->id,
                'uuid'          => $c->uuid,
                'case_number'   => $c->case_number,
                'title'         => $c->title,
                'employee_name' => $c->employeeName(),
                'category'      => $c->category?->name,
                'severity'      => $c->category?->severity_level,
                'status'        => $c->status?->name,
                'status_color'  => $c->status?->color,
                'incident_date' => $c->incident_date?->format('d M Y'),
                'hearing_date'  => $c->hearings->first()?->scheduled_at?->format('d M Y H:i'),
                'is_closed'     => $c->isClosed(),
                'created_at'    => $c->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Modules/Disciplinary/Cases/Index', [
            'cases'      => $cases,
            'statuses'   => DisciplinaryStatus::orderBy('sort_order')->get(['id', 'name', 'color', 'is_closed']),
            'categories' => DisciplinaryCategory::where('active', true)->orderBy('name')->get(['id', 'name', 'severity_level']),
            'canCreate'  => $this->isDiscAdmin(),
            'employees'  => $this->isDiscAdmin()
                ? User::join('hr_people as p', 'p.user_id', '=', 'users.id')
                    ->where('users.status', 1)
                    ->orderByRaw("p.first_name || ' ' || p.surname")
                    ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email'])
                : collect(),
        ]);
    }

    public function show(DisciplinaryCase $case): Response
    {
        $role = $this->caseViewerRole($case);
        abort_if($role === 'none', 403, 'You do not have access to this case.');

        $case->load(['employee', 'investigator', 'chairperson', 'category', 'status', 'outcome', 'sanction']);

        $activityLogs = Activity::with('causer.person')
            ->where('subject_type', DisciplinaryCase::class)
            ->where('subject_id', $case->id)
            ->latest()
            ->get()
            ->map(fn($l) => [
                'id'          => $l->id,
                'event'       => $l->event,
                'description' => $l->description,
                'properties'  => $l->properties,
                'user'        => $l->causer?->person
                    ? trim("{$l->causer->person->first_name} {$l->causer->person->surname}")
                    : ($l->causer?->email ?? 'System'),
                'created_at'  => $l->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Modules/Disciplinary/Cases/Show', [
            'disciplinaryCase' => [
                'id'                     => $case->id,
                'uuid'                   => $case->uuid,
                'case_number'            => $case->case_number,
                'title'                  => $case->title,
                'description'            => $case->description,
                'employee_id'            => $case->employee_id,
                'employee_name'          => $case->employeeName(),
                'investigator_id'        => $case->investigator_id,
                'investigator_name'      => $this->userName($case->investigator),
                'chairperson_id'         => $case->chairperson_id,
                'chairperson_name'       => $this->userName($case->chairperson),
                'category_id'            => $case->category_id,
                'category'               => $case->category?->name,
                'severity'               => $case->category?->severity_level,
                'status_id'              => $case->status_id,
                'status'                 => $case->status?->name,
                'status_color'           => $case->status?->color,
                'outcome_id'             => $case->outcome_id,
                'outcome'                => $case->outcome?->name,
                'sanction_id'            => $case->sanction_id,
                'sanction'               => $case->sanction?->name,
                'incident_date'          => $case->incident_date?->format('Y-m-d'),
                'notice_issued_date'     => $case->notice_issued_date?->format('Y-m-d'),
                'hearing_date'           => $case->hearing_date?->toIso8601String(),
                'closed_at'              => $case->closed_at?->toIso8601String(),
                'policy_reference'       => $case->policy_reference,
                'outcome_notes'          => $case->outcome_notes,
                'internal_notes'         => $case->internal_notes,
                'policy_engine_checked'  => $case->policy_engine_checked,
                'policy_violations'      => $case->policy_violations,
                'is_closed'              => $case->isClosed(),
                'created_at'             => $case->created_at?->format('d M Y'),
            ],
            'viewerRole'   => $role,
            'activityLogs' => $activityLogs,
            'statuses'     => DisciplinaryStatus::orderBy('sort_order')->get(['id', 'name', 'color', 'is_closed']),
            'outcomes'     => DisciplinaryOutcome::where('active', true)->orderBy('name')->get(['id', 'name', 'requires_sanction']),
            'sanctions'    => DisciplinarySanction::where('active', true)->orderBy('name')->get(['id', 'name', 'severity_level', 'duration_days']),
            'employees'    => $this->isDiscAdmin()
                ? User::join('hr_people as p', 'p.user_id', '=', 'users.id')
                    ->where('users.status', 1)
                    ->orderByRaw("p.first_name || ' ' || p.surname")
                    ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email'])
                : collect(),
        ]);
    }

    // ── store() — admin/HR only ───────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'      => 'required|exists:users,id',
            'category_id'      => 'nullable|exists:disciplinary_categories,id',
            'title'            => 'required|string|max:200',
            'description'      => 'required|string',
            'incident_date'    => 'required|date',
            'policy_reference' => 'nullable|string|max:120',
            'internal_notes'   => 'nullable|string',
        ]);

        $defaultStatus = DisciplinaryStatus::where('is_closed', false)->orderBy('sort_order')->first();

        $case = DisciplinaryCase::create(array_merge($data, [
            'status_id'  => $defaultStatus?->id,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]));

        // Policy check — runs AFTER create so the case ID is available for logging
        $policy = $this->policyGuard->forCaseCreated($case->fresh(), auth()->id());
        $warnings = $policy['warnings'];

        activity('disciplinary')
            ->performedOn($case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'case_created', 'case_number' => $case->case_number])
            ->log("Case {$case->case_number} created.");

        $successMsg = "Case {$case->case_number} created.";
        if (!empty($warnings)) {
            $successMsg .= ' Policy warning: ' . implode(' | ', $warnings);
        }

        return redirect()->route('disciplinary.cases.show', $case)
            ->with('success', $successMsg)
            ->with('policy_warnings', $warnings);
    }

    // ── updateStatus() — admin/HR only ────────────────────────────────────────

    public function updateStatus(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'status_id' => 'required|exists:disciplinary_statuses,id',
            'notes'     => 'nullable|string',
        ]);

        $old = $case->status?->name ?? 'none';
        $case->update(['status_id' => $data['status_id'], 'updated_by' => auth()->id()]);
        $new = DisciplinaryStatus::find($data['status_id'])?->name ?? 'unknown';

        activity('disciplinary')
            ->performedOn($case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'status_changed', 'before' => $old, 'after' => $new, 'notes' => $data['notes'] ?? null])
            ->log("Status changed from '{$old}' to '{$new}'.");

        return back()->with('success', 'Case status updated.');
    }

    // ── assignInvestigator() — admin/HR only ─────────────────────────────────

    public function assignInvestigator(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['investigator_id' => 'required|exists:users,id']);

        $this->policyGuard->forInvestigatorAssigned($case->load('category'), (int) $data['investigator_id'], auth()->id());

        $case->update(['investigator_id' => $data['investigator_id'], 'updated_by' => auth()->id()]);
        $investigator = User::find($data['investigator_id']);
        $name = $this->userName($investigator);

        $investigator?->notify(new DisciplinaryCaseAssignedNotification($case->fresh(), 'investigator'));

        activity('disciplinary')
            ->performedOn($case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'investigator_assigned', 'investigator' => $name])
            ->log("Investigator assigned: {$name}.");

        return back()->with('success', "Investigator {$name} assigned.");
    }

    // ── assignChairperson() — admin/HR only ──────────────────────────────────

    public function assignChairperson(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['chairperson_id' => 'required|exists:users,id']);

        $this->policyGuard->forChairpersonAssigned($case->load('category'), (int) $data['chairperson_id'], auth()->id());

        $case->update(['chairperson_id' => $data['chairperson_id'], 'updated_by' => auth()->id()]);
        $chairperson = User::find($data['chairperson_id']);
        $name = $this->userName($chairperson);

        $chairperson?->notify(new DisciplinaryCaseAssignedNotification($case->fresh(), 'chairperson'));

        activity('disciplinary')
            ->performedOn($case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'chairperson_assigned', 'chairperson' => $name])
            ->log("Chairperson assigned: {$name}.");

        return back()->with('success', "Chairperson {$name} assigned.");
    }

    // ── closeCase() — admin/HR only ───────────────────────────────────────────

    public function closeCase(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'outcome_id'    => 'required|exists:disciplinary_outcomes,id',
            'sanction_id'   => 'nullable|exists:disciplinary_sanctions,id',
            'outcome_notes' => 'nullable|string',
        ]);

        $outcomeName = DisciplinaryOutcome::find($data['outcome_id'])?->name ?? null;
        $this->policyGuard->forCaseClosed($case->load(['hearings', 'investigations', 'category']), $outcomeName, auth()->id());

        $closedStatus = DisciplinaryStatus::where('is_closed', true)->orderBy('sort_order')->first();

        $case->update([
            'outcome_id'    => $data['outcome_id'],
            'sanction_id'   => $data['sanction_id'] ?? null,
            'outcome_notes' => $data['outcome_notes'] ?? null,
            'status_id'     => $closedStatus?->id,
            'closed_at'     => now(),
            'updated_by'    => auth()->id(),
        ]);

        $outcome = DisciplinaryOutcome::find($data['outcome_id'])?->name ?? 'unknown';
        activity('disciplinary')
            ->performedOn($case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'case_closed', 'outcome' => $outcome])
            ->log("Case {$case->case_number} closed. Outcome: {$outcome}.");

        return back()->with('success', "Case {$case->case_number} has been closed.");
    }

    // ── update() — admin/HR only ──────────────────────────────────────────────

    public function update(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'title'              => 'required|string|max:200',
            'description'        => 'required|string',
            'incident_date'      => 'required|date',
            'notice_issued_date' => 'nullable|date',
            'hearing_date'       => 'nullable|date',
            'policy_reference'   => 'nullable|string|max:120',
            'outcome_notes'      => 'nullable|string',
            'internal_notes'     => 'nullable|string',
            'category_id'        => 'nullable|exists:disciplinary_categories,id',
        ]);

        $case->update(array_merge($data, ['updated_by' => auth()->id()]));

        activity('disciplinary')
            ->performedOn($case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'case_updated'])
            ->log("Case {$case->case_number} updated.");

        return back()->with('success', 'Case updated.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function userName(?User $user): string
    {
        if (! $user) return 'Unknown';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? 'Unknown');
    }
}
