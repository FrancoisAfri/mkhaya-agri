<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCoachingSession;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryRehabilitationProgress;
use App\Modules\Disciplinary\Services\CorrectiveActionService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryCorrectiveActionController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(private readonly CorrectiveActionService $service) {}

    // ── Corrective actions index ──────────────────────────────────────────────

    public function index(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $actions = DisciplinaryCorrectiveAction::with(['employee.person', 'assignedBy', 'case'])
            ->when($request->employee_id, fn($q, $id) => $q->where('employee_id', $id))
            ->when($request->status,      fn($q, $s)  => $q->where('status', $s))
            ->when($request->type,        fn($q, $t)  => $q->where('action_type', $t))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn($a) => $this->mapAction($a));

        $stats = [
            'pending'    => DisciplinaryCorrectiveAction::where('status', 'pending')->count(),
            'in_progress'=> DisciplinaryCorrectiveAction::where('status', 'in_progress')->count(),
            'completed'  => DisciplinaryCorrectiveAction::where('status', 'completed')->count(),
            'overdue'    => DisciplinaryCorrectiveAction::overdue()->count(),
        ];

        return Inertia::render('Modules/Disciplinary/CorrectiveActions/Index', [
            'actions'    => $actions,
            'stats'      => $stats,
            'filters'    => $request->only('employee_id', 'status', 'type'),
            'employees'  => $this->employeeList(),
            'open_cases' => $this->openCaseList(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'    => 'required|exists:users,id',
            'action_type'    => 'required|in:training,counseling,coaching,performance_plan,work_shadowing,mentoring,other',
            'title'          => 'required|string|max:255',
            'description'    => 'required|string',
            'due_date'       => 'required|date|after:today',
            'case_id'        => 'nullable|exists:disciplinary_cases,id',
            'is_mandatory'   => 'boolean',
        ]);

        $action = $this->service->createCorrectiveAction(array_merge($data, [
            'assigned_by'   => auth()->id(),
            'assigned_date' => now()->toDateString(),
        ]));

        return redirect()->back()->with('success', "Corrective action {$action->action_number} created.");
    }

    public function show(DisciplinaryCorrectiveAction $action): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $action->loadMissing(['employee.person', 'assignedBy', 'case', 'coachingSessions.coach']);

        $sessions = $action->coachingSessions->map(fn($s) => $this->mapSession($s));

        $rehab = DisciplinaryRehabilitationProgress::where('employee_id', $action->employee_id)
            ->orderByDesc('period_end')->limit(6)->get()
            ->map(fn($r) => [
                'period_end'    => $r->period_end?->format('d M Y'),
                'overall_score' => $r->overall_score,
                'trend'         => $r->trend,
            ]);

        return Inertia::render('Modules/Disciplinary/CorrectiveActions/Show', [
            'action'    => $this->mapAction($action),
            'sessions'  => $sessions,
            'rehab'     => $rehab,
            'employees' => $this->employeeList(),
        ]);
    }

    public function updateProgress(Request $request, DisciplinaryCorrectiveAction $action): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'progress_percent' => 'required|integer|min:0|max:100',
            'progress_notes'   => 'required|string',
        ]);

        $this->service->updateProgress($action, $data['progress_percent'], $data['progress_notes']);
        return redirect()->back()->with('success', 'Progress updated.');
    }

    public function recommendActions(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['case_id' => 'required|exists:disciplinary_cases,id']);

        $case        = \App\Modules\Disciplinary\Models\DisciplinaryCase::findOrFail($data['case_id']);
        $recommended = $this->service->recommendCorrectiveActions($case);

        return redirect()->back()->with([
            'success'            => 'AI recommendations generated.',
            'recommendations'    => $recommended,
        ]);
    }

    // ── Coaching sessions ─────────────────────────────────────────────────────

    public function storeSession(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'        => 'required|exists:users,id',
            'coach_id'           => 'required|exists:users,id',
            'session_type'       => 'required|in:coaching,counseling,mentoring,mediation,performance_review',
            'scheduled_at'       => 'required|date',
            'duration_minutes'   => 'nullable|integer|min:15',
            'venue'              => 'nullable|string|max:255',
            'agenda'             => 'nullable|string',
            'corrective_action_id'=> 'nullable|exists:disciplinary_corrective_actions,id',
            'case_id'            => 'nullable|exists:disciplinary_cases,id',
        ]);

        $session = $this->service->scheduleCoachingSession($data);
        return redirect()->back()->with('success', "Session scheduled for {$session->scheduled_at->format('d M Y H:i')}.");
    }

    public function completeSession(Request $request, DisciplinaryCoachingSession $session): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'session_notes' => 'required|string',
            'outcome'       => 'required|string',
        ]);

        $this->service->completeSession($session, $data['session_notes'], $data['outcome']);
        return redirect()->back()->with('success', 'Session completed.');
    }

    public function rescheduleSession(Request $request, DisciplinaryCoachingSession $session): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['new_date' => 'required|date|after:now']);
        $this->service->rescheduleSession($session, $data['new_date']);
        return redirect()->back()->with('success', 'Session rescheduled.');
    }

    // ── Rehabilitation progress ───────────────────────────────────────────────

    public function storeRehabilitation(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'employee_id'        => 'required|exists:users,id',
            'case_id'            => 'nullable|exists:disciplinary_cases,id',
            'period_start'       => 'required|date',
            'period_end'         => 'required|date|after:period_start',
            'scores.behavior'    => 'required|numeric|min:0|max:10',
            'scores.attendance'  => 'required|numeric|min:0|max:10',
            'scores.performance' => 'required|numeric|min:0|max:10',
            'scores.compliance'  => 'required|numeric|min:0|max:10',
            'notes'              => 'nullable|string',
        ]);

        $progress = $this->service->recordRehabilitationProgress(
            $data['employee_id'], $data['case_id'] ?? null,
            $data['period_start'], $data['period_end'],
            $data['scores'], $data['notes'] ?? null, auth()->id()
        );

        return redirect()->back()->with('success', "Rehabilitation progress recorded — trend: {$progress->trend}");
    }

    // ── Map helpers ───────────────────────────────────────────────────────────

    private function mapAction(DisciplinaryCorrectiveAction $a): array
    {
        return [
            'id'               => $a->id,
            'action_number'    => $a->action_number,
            'employee_id'      => $a->employee_id,
            'employee'         => $a->employee->person?->full_name ?? $a->employee->email,
            'action_type'      => $a->action_type,
            'title'            => $a->title,
            'description'      => $a->description,
            'due_date'         => $a->due_date?->format('d M Y'),
            'completed_date'   => $a->completed_date?->format('d M Y'),
            'status'           => $a->status,
            'progress_percent' => $a->progress_percent,
            'progress_notes'   => $a->progress_notes,
            'is_mandatory'     => $a->is_mandatory,
            'ai_recommended'   => $a->ai_recommended,
            'ai_reasoning'     => $a->ai_reasoning,
            'case_number'      => $a->case?->case_number,
            'assigned_by'      => $a->assignedBy?->person?->full_name,
        ];
    }

    private function mapSession(DisciplinaryCoachingSession $s): array
    {
        return [
            'id'              => $s->id,
            'session_type'    => $s->session_type,
            'scheduled_at'    => $s->scheduled_at?->format('d M Y H:i'),
            'duration_minutes'=> $s->duration_minutes,
            'venue'           => $s->venue,
            'status'          => $s->status,
            'coach'           => $s->coach?->person?->full_name,
            'outcome'         => $s->outcome,
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
