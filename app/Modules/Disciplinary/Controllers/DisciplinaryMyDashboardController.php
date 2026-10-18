<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryAppeal;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCoachingSession;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigation;
use App\Modules\Disciplinary\Models\DisciplinaryWarning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryMyDashboardController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();

        // Is this user a manager (has direct reports)?
        $myPersonId = DB::table('hr_people')->where('user_id', $userId)->value('id');
        $hasReports = $myPersonId
            ? DB::table('hr_people')->where('manager_id', $myPersonId)->exists()
            : false;

        // Cases where I am the accused employee
        $myCases = DisciplinaryCase::with(['category', 'status', 'employee.person'])
            ->where('employee_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($c) => $this->summariseCase($c));

        // Investigations I am assigned to as investigator
        $myInvestigations = DisciplinaryInvestigation::with(['case.employee.person', 'case.category', 'case.status'])
            ->where('investigator_id', $userId)
            ->whereNotIn('status', ['completed', 'suspended'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($i) => [
                'id'                   => $i->id,
                'investigation_number' => $i->investigation_number,
                'case_id'              => $i->case_id,
                'case_number'          => $i->case?->case_number,
                'case_title'           => $i->case?->title,
                'employee_name'        => $i->case?->employeeName() ?? '—',
                'category'             => $i->case?->category?->name,
                'severity'             => $i->case?->category?->severity_level,
                'status'               => $i->status,
                'status_color'         => $i->statusColor(),
                'due_date'             => $i->due_date?->format('d M Y'),
                'progress'             => $i->checklistProgress(),
                'internal_notes'       => $i->case?->internal_notes,
                'created_at'           => $i->created_at?->format('d M Y'),
            ]);

        // Hearings I'm involved in (chairperson, HR rep, or registered attendee)
        $myHearings = DisciplinaryHearing::with(['case.employee.person', 'case.category'])
            ->where(function ($q) use ($userId) {
                $q->where('chairperson_id', $userId)
                  ->orWhere('hr_rep_id', $userId)
                  ->orWhereHas('attendees', fn($a) => $a->where('user_id', $userId));
            })
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(function ($h) use ($userId) {
                $myRole = match(true) {
                    $h->chairperson_id === $userId => 'Chairperson',
                    $h->hr_rep_id === $userId      => 'HR Representative',
                    default                        => 'Attendee',
                };
                return [
                    'id'             => $h->id,
                    'hearing_number' => $h->hearing_number,
                    'case_id'        => $h->case_id,
                    'case_number'    => $h->case?->case_number,
                    'case_title'     => $h->case?->title,
                    'employee_name'  => $h->case?->employeeName() ?? '—',
                    'category'       => $h->case?->category?->name,
                    'severity'       => $h->case?->category?->severity_level,
                    'hearing_type'   => $h->hearing_type,
                    'status'         => $h->status,
                    'status_color'   => $h->statusColor(),
                    'scheduled_at'   => $h->scheduled_at?->format('d M Y H:i'),
                    'venue'          => $h->venue,
                    'venue_type'     => $h->venue_type,
                    'notice_issued'  => $h->notice_issued,
                    'my_role'        => $myRole,
                    'created_at'     => $h->created_at?->format('d M Y'),
                ];
            });

        // Incidents I reported
        $myIncidents = DisciplinaryIncident::with(['category', 'investigator.person'])
            ->where('reporter_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($i) => $this->summariseIncident($i));

        // Incidents assigned to me as investigator
        $investigatingIncidents = DisciplinaryIncident::with(['category', 'reporter.person'])
            ->where('investigator_id', $userId)
            ->whereNotIn('status', ['closed', 'dismissed'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($i) => $this->summariseIncident($i));

        // Team cases (if manager)
        $teamCases = collect();
        if ($hasReports) {
            $reportIds = DB::table('hr_people')
                ->where('manager_id', $myPersonId)
                ->join('users', 'users.id', '=', 'hr_people.user_id')
                ->where('users.status', 1)
                ->pluck('users.id');

            $teamCases = DisciplinaryCase::with(['category', 'status', 'employee.person'])
                ->whereIn('employee_id', $reportIds)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn($c) => $this->summariseCase($c));
        }

        // Active warnings against me
        $myWarnings = DisciplinaryWarning::forEmployee($userId)
            ->active()
            ->with('case')
            ->orderByDesc('issued_date')
            ->get()
            ->map(fn($w) => [
                'id'           => $w->id,
                'warning_type' => $w->warning_type,
                'case_number'  => $w->case?->case_number,
                'case_id'      => $w->case_id,
                'issued_date'  => $w->issued_date?->format('d M Y'),
                'expiry_date'  => $w->expiry_date?->format('d M Y'),
                'reason'       => $w->reason,
                'is_expired'   => $w->is_expired,
            ]);

        // Corrective actions assigned to me
        $myCorrectiveActions = DisciplinaryCorrectiveAction::where('employee_id', $userId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['case', 'assignedBy.person'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($a) => [
                'id'               => $a->id,
                'action_number'    => $a->action_number,
                'action_type'      => $a->action_type,
                'title'            => $a->title,
                'due_date'         => $a->due_date?->format('d M Y'),
                'status'           => $a->status,
                'progress_percent' => $a->progress_percent,
                'case_number'      => $a->case?->case_number,
                'case_id'          => $a->case_id,
                'assigned_by'      => $a->assignedBy?->person?->full_name ?? $a->assignedBy?->email,
                'is_mandatory'     => $a->is_mandatory,
            ]);

        // Appeals I submitted
        $myAppeals = DisciplinaryAppeal::where('employee_id', $userId)
            ->with(['case', 'outcome'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn($a) => [
                'id'            => $a->id,
                'appeal_number' => $a->appeal_number,
                'case_number'   => $a->case?->case_number,
                'case_id'       => $a->case_id,
                'grounds'       => $a->grounds,
                'status'        => $a->status,
                'submitted_at'  => $a->submitted_at?->format('d M Y'),
                'verdict'       => $a->outcome?->verdict,
            ]);

        // Coaching sessions I'm involved in (as employee or coach)
        $myCoachingSessions = DisciplinaryCoachingSession::with(['correctiveAction', 'case', 'coach.person', 'employee.person'])
            ->where(fn($q) => $q->where('employee_id', $userId)->orWhere('coach_id', $userId))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn($s) => [
                'id'                   => $s->id,
                'session_type'         => $s->session_type,
                'scheduled_at'         => $s->scheduled_at?->format('d M Y H:i'),
                'scheduled_at_iso'     => $s->scheduled_at?->toISOString(),
                'duration_minutes'     => $s->duration_minutes,
                'venue'                => $s->venue,
                'status'               => $s->status,
                'my_role'              => $s->coach_id === $userId ? 'Coach' : 'Participant',
                'with_person'          => $s->coach_id === $userId
                    ? ($s->employee?->person?->full_name ?? $s->employee?->email ?? '—')
                    : ($s->coach?->person?->full_name ?? $s->coach?->email ?? '—'),
                'case_number'          => $s->case?->case_number,
                'corrective_action_id' => $s->corrective_action_id,
                'agenda'               => $s->agenda,
            ]);

        // Build unified calendar events from hearings + coaching sessions + corrective action due dates
        $calendarEvents = collect();

        foreach ($myHearings as $h) {
            if ($h['scheduled_at']) {
                $calendarEvents->push([
                    'type'       => 'hearing',
                    'title'      => 'Hearing — ' . ($h['case_number'] ?? ''),
                    'subtitle'   => $h['my_role'],
                    'date_iso'   => \Carbon\Carbon::createFromFormat('d M Y H:i', $h['scheduled_at'])?->toISOString(),
                    'date_label' => $h['scheduled_at'],
                    'venue'      => $h['venue'],
                    'color'      => 'deep-purple',
                    'icon'       => 'mdi-gavel',
                    'ref_id'     => $h['id'],
                    'ref_type'   => 'hearing',
                ]);
            }
        }

        foreach ($myCoachingSessions as $s) {
            if ($s['scheduled_at_iso']) {
                $calendarEvents->push([
                    'type'       => 'coaching',
                    'title'      => ucfirst(str_replace('_', ' ', $s['session_type'])) . ' Session',
                    'subtitle'   => $s['my_role'] . ' with ' . $s['with_person'],
                    'date_iso'   => $s['scheduled_at_iso'],
                    'date_label' => $s['scheduled_at'],
                    'venue'      => $s['venue'],
                    'color'      => 'teal',
                    'icon'       => 'mdi-account-voice',
                    'ref_id'     => $s['id'],
                    'ref_type'   => 'session',
                ]);
            }
        }

        foreach ($myCorrectiveActions as $a) {
            if ($a['due_date']) {
                $calendarEvents->push([
                    'type'       => 'corrective_action',
                    'title'      => 'Due: ' . $a['title'],
                    'subtitle'   => 'Corrective Action — ' . $a['action_number'],
                    'date_iso'   => \Carbon\Carbon::createFromFormat('d M Y', $a['due_date'])?->endOfDay()->toISOString(),
                    'date_label' => $a['due_date'],
                    'venue'      => null,
                    'color'      => 'indigo',
                    'icon'       => 'mdi-clipboard-check-outline',
                    'ref_id'     => $a['id'],
                    'ref_type'   => 'corrective_action',
                ]);
            }
        }

        $calendarEvents = $calendarEvents->sortBy('date_iso')->values();

        $user = Auth::user();
        $canSeeDashboard = $user->hasRole(['super admin', 'administrator', 'HR', 'executive', 'senior manager']);

        return Inertia::render('Modules/Disciplinary/MyDashboard/Index', [
            'myCases'                => $myCases,
            'myInvestigations'       => $myInvestigations,
            'myHearings'             => $myHearings,
            'myIncidents'            => $myIncidents,
            'investigatingIncidents' => $investigatingIncidents,
            'teamCases'              => $teamCases,
            'hasReports'             => $hasReports,
            'canSeeDashboard'        => $canSeeDashboard,
            'myWarnings'             => $myWarnings,
            'myCorrectiveActions'    => $myCorrectiveActions,
            'myAppeals'              => $myAppeals,
            'myCoachingSessions'     => $myCoachingSessions,
            'calendarEvents'         => $calendarEvents,
        ]);
    }

    // ── Save investigator notes on a case ─────────────────────────────────────
    public function saveNotes(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $userId = Auth::id();

        $hasInvestigation = DisciplinaryInvestigation::where('case_id', $case->id)
            ->where('investigator_id', $userId)->exists();
        $hasHearing = DisciplinaryHearing::where('case_id', $case->id)
            ->where('chairperson_id', $userId)->exists();

        abort_unless($hasInvestigation || $hasHearing, 403);

        $data = $request->validate(['internal_notes' => 'nullable|string']);
        $case->update(['internal_notes' => $data['internal_notes'], 'updated_by' => $userId]);

        activity('disciplinary')
            ->performedOn($case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'notes_updated'])
            ->log("Case notes updated.");

        return back()->with('success', 'Notes saved.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function summariseCase(DisciplinaryCase $c): array
    {
        return [
            'id'             => $c->id,
            'case_number'    => $c->case_number,
            'title'          => $c->title,
            'employee_name'  => $c->employeeName(),
            'category'       => $c->category?->name,
            'severity'       => $c->category?->severity_level,
            'status'         => $c->status?->name ?? 'No Status',
            'status_color'   => $c->status?->color ?? 'grey',
            'is_closed'      => $c->isClosed(),
            'incident_date'  => $c->incident_date?->format('d M Y'),
            'internal_notes' => $c->internal_notes,
            'created_at'     => $c->created_at?->format('d M Y'),
        ];
    }

    private function summariseIncident(DisciplinaryIncident $i): array
    {
        return [
            'id'              => $i->id,
            'incident_number' => $i->incident_number,
            'title'           => $i->title,
            'category'        => $i->category?->name,
            'severity_level'  => $i->severity_level,
            'status'          => $i->status,
            'status_color'    => DisciplinaryIncident::statusColor($i->status),
            'incident_date'   => $i->incident_date?->format('d M Y H:i'),
            'investigator'    => $this->personName($i->investigator),
            'auto_assigned'   => $i->auto_assigned,
            'is_anonymous'    => $i->is_anonymous,
            'created_at'      => $i->created_at?->format('d M Y'),
        ];
    }

    private function personName($user): string
    {
        if (! $user) return '—';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? '—');
    }
}
