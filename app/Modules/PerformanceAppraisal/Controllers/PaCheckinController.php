<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaCoachingSession;
use App\Modules\PerformanceAppraisal\Notifications\PaCheckinNotification;
use App\Modules\PerformanceAppraisal\Notifications\PaCoachingSessionNotification;
use App\Modules\PerformanceAppraisal\Services\PerformanceAppraisalPolicyGuard;
use App\Modules\PerformanceAppraisal\Trait\ReportsHierarchy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaCheckinController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    use SafeNotifies, ReportsHierarchy;

    public function __construct(private PerformanceAppraisalPolicyGuard $policyGuard) {}

    public function index(Request $request): Response
    {
        $user    = $request->user();
        $cycleId = $request->query('cycle_id');
        $allowed = $this->getAllowedEmployeeIds($user);

        return Inertia::render('Modules/PerformanceAppraisal/CheckIns/Index', [
            'checkins'  => PaCheckin::with(['employee', 'manager', 'cycle', 'talkingPoints.author', 'actionItemRecords.owner'])
                ->when($allowed !== null, fn($q) => $q->whereIn('employee_id', $allowed))
                ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
                ->orderByDesc('scheduled_at')
                ->get()
                ->map(fn($c) => [
                    'id'               => $c->id,
                    'title'            => $c->title,
                    'employee_name'    => $c->employee->email ?? '-',
                    'manager_name'     => $c->manager->email ?? '-',
                    'cycle_name'       => $c->cycle?->name,
                    'scheduled_at'     => $c->scheduled_at?->format('d M Y H:i'),
                    'completed_at'     => $c->completed_at?->format('d M Y H:i'),
                    'duration_minutes' => $c->duration_minutes,
                    'location'         => $c->location,
                    'status'           => $c->status,
                    'agenda'           => $c->agenda,
                    'notes'            => $c->notes,
                    'action_items'     => $c->action_items ?? [],
                    'employee_id'      => $c->employee_id,
                    'manager_id'       => $c->manager_id,
                    'cycle_id'         => $c->cycle_id,
                    'talking_points'   => $c->talkingPoints->map(fn($tp) => [
                        'id' => $tp->id, 'body' => $tp->body, 'author_role' => $tp->author_role,
                        'is_discussed' => $tp->is_discussed,
                    ])->values(),
                    'action_item_records' => $c->actionItemRecords->map(fn($ai) => [
                        'id' => $ai->id, 'description' => $ai->description,
                        'due_date' => $ai->due_date?->format('Y-m-d'), 'status' => $ai->status,
                        'owner_name' => $ai->owner?->email,
                    ])->values(),
                ]),
            'sessions'  => PaCoachingSession::with(['employee', 'coach'])
                ->when($allowed !== null, fn($q) => $q->whereIn('employee_id', $allowed))
                ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
                ->orderByDesc('session_date')
                ->get()
                ->map(fn($s) => [
                    'id'               => $s->id,
                    'title'            => $s->title,
                    'employee_name'    => $s->employee->email ?? '-',
                    'coach_name'       => $s->coach->email ?? '-',
                    'session_date'     => $s->session_date?->format('Y-m-d'),
                    'duration_minutes' => $s->duration_minutes,
                    'objectives'       => $s->objectives,
                    'outcomes'         => $s->outcomes,
                    'next_steps'       => $s->next_steps,
                    'status'           => $s->status,
                    'employee_id'      => $s->employee_id,
                    'coach_id'         => $s->coach_id,
                    'cycle_id'         => $s->cycle_id,
                ]),
            'cycles'    => PaCycle::orderByDesc('start_date')->get(['id', 'name']),
            'employees' => $this->personList(),
            'selectedCycleId' => $cycleId ? (int) $cycleId : null,
        ]);
    }

    // ── Check-ins ─────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'      => ['required', 'exists:users,id'],
            'manager_id'       => ['required', 'exists:users,id'],
            'cycle_id'         => ['nullable', 'exists:pa_cycles,id'],
            'title'            => ['required', 'string', 'max:200'],
            'scheduled_at'     => ['required', 'date'],
            'duration_minutes' => ['integer', 'min:5'],
            'location'         => ['nullable', 'string', 'max:200'],
            'agenda'           => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()->id;

        $checkin = PaCheckin::create($data);

        $employee = User::find($checkin->employee_id);
        $manager  = User::find($checkin->manager_id);

        if ($employee) $this->safeNotify($employee, new PaCheckinNotification($checkin, 'scheduled'));
        if ($manager)  $this->safeNotify($manager,  new PaCheckinNotification($checkin, 'scheduled'));

        return back()->with('success', 'Check-in scheduled.');
    }

    public function update(Request $request, PaCheckin $checkin): RedirectResponse
    {
        $data = $request->validate([
            'title'            => ['sometimes', 'string', 'max:200'],
            'scheduled_at'     => ['sometimes', 'date'],
            'duration_minutes' => ['integer', 'min:5'],
            'location'         => ['nullable', 'string', 'max:200'],
            'agenda'           => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
            'action_items'     => ['nullable', 'array'],
            'action_items.*.task'     => ['required', 'string'],
            'action_items.*.owner'    => ['nullable', 'string'],
            'action_items.*.due_date' => ['nullable', 'date'],
            'status'           => ['sometimes', 'in:scheduled,completed,cancelled'],
            'completed_at'     => ['nullable', 'date'],
        ]);

        $checkin->update($data);

        if (($data['status'] ?? null) === 'completed') {
            $this->policyGuard->forCheckinCompleted($checkin, $request->user()->id);
        }

        return back()->with('success', 'Check-in updated.');
    }

    public function destroy(PaCheckin $checkin): RedirectResponse
    {
        $checkin->delete();

        return back()->with('success', 'Check-in deleted.');
    }

    // ── Coaching sessions ─────────────────────────────────────────────────────

    public function sessionStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'      => ['required', 'exists:users,id'],
            'coach_id'         => ['required', 'exists:users,id'],
            'cycle_id'         => ['nullable', 'exists:pa_cycles,id'],
            'title'            => ['required', 'string', 'max:200'],
            'session_date'     => ['required', 'date'],
            'duration_minutes' => ['integer', 'min:5'],
            'objectives'       => ['nullable', 'string'],
            'status'           => ['sometimes', 'in:scheduled,completed,cancelled'],
        ]);
        $data['created_by'] = $request->user()->id;

        $session = PaCoachingSession::create($data);

        $employee = User::find($session->employee_id);
        $coach    = User::find($session->coach_id);

        if ($employee) $this->safeNotify($employee, new PaCoachingSessionNotification($session, 'scheduled', 'employee'));
        if ($coach)    $this->safeNotify($coach,    new PaCoachingSessionNotification($session, 'scheduled', 'coach'));

        return back()->with('success', 'Coaching session created.');
    }

    public function sessionUpdate(Request $request, PaCoachingSession $session): RedirectResponse
    {
        $data = $request->validate([
            'title'            => ['sometimes', 'string', 'max:200'],
            'session_date'     => ['sometimes', 'date'],
            'duration_minutes' => ['integer', 'min:5'],
            'objectives'       => ['nullable', 'string'],
            'outcomes'         => ['nullable', 'string'],
            'next_steps'       => ['nullable', 'string'],
            'status'           => ['sometimes', 'in:scheduled,completed,cancelled'],
        ]);

        $session->update($data);

        return back()->with('success', 'Session updated.');
    }

    public function sessionDestroy(PaCoachingSession $session): RedirectResponse
    {
        $session->delete();

        return back()->with('success', 'Coaching session deleted.');
    }

    /**

     * Everybody this tenant may choose between.

     *

     * Was built here, in two different shapes across these controllers — some through

     * `User`, which leans on a global scope that steps aside for a platform administrator,

     * and some as a raw `DB::table('users')` where no scope applied at all and `users`

     * carries no row-level security to catch it. One of them offered every tenant's people

     * to every other tenant; the ones reading `hr_people` offered almost nobody.

     */

    private function personList(): \Illuminate\Support\Collection

    {

        return collect($this->tenantPeopleOptions());

    }
}
