<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryAiEvidenceReport;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryHearingAttendee;
use App\Modules\Disciplinary\Models\DisciplinaryHearingMinute;
use App\Modules\Disciplinary\Models\DisciplinaryHearingOutcome;
use App\Modules\Disciplinary\Models\DisciplinaryOutcome;
use App\Modules\Disciplinary\Models\DisciplinarySanction;
use App\Modules\Disciplinary\Notifications\DisciplinaryHearingScheduledNotification;
use App\Modules\Disciplinary\Services\DisciplinaryEvidenceIntelligenceService;
use App\Modules\Disciplinary\Services\DisciplinaryPolicyGuard;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryHearingController extends Controller
{
    use DisciplinaryAuthTrait, FileStorage;

    public function __construct(
        protected DisciplinaryEvidenceIntelligenceService $evidenceAi,
        protected DisciplinaryPolicyGuard $policyGuard,
    ) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $hearings = DisciplinaryHearing::with(['case.employee.person', 'chairperson.person'])
            ->orderByDesc('scheduled_at')
            ->paginate(25)
            ->through(fn($h) => $this->summarise($h));

        $cases = DisciplinaryCase::with(['employee.person', 'status'])
            ->where(function ($q) {
                $q->whereDoesntHave('status')
                  ->orWhereHas('status', fn($q2) => $q2->where('is_closed', false));
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($c) => [
                'id'    => $c->id,
                'label' => "{$c->case_number} — {$c->employeeName()} ({$c->title})",
            ]);

        return Inertia::render('Modules/Disciplinary/Hearings/Index', [
            'hearings'  => $hearings,
            'employees' => $this->employeeList(),
            'cases'     => $cases,
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(DisciplinaryHearing $hearing): Response
    {
        $userId = auth()->id();
        abort_unless(
            $this->isDiscAdmin() ||
            $hearing->chairperson_id === $userId ||
            $hearing->hr_rep_id === $userId ||
            $hearing->case?->employee_id === $userId,
            403
        );

        $hearing->load(['case.employee.person', 'case.category', 'chairperson.person',
            'initiator.person', 'hrRep.person', 'attendees.user.person', 'minutes.recorder.person', 'outcome.outcome', 'outcome.sanction']);

        $attendees = $hearing->attendees->map(fn($a) => [
            'id'               => $a->id,
            'user_id'          => $a->user_id,
            'display_name'     => $a->displayName(),
            'role'             => $a->role,
            'role_label'       => DisciplinaryHearingAttendee::roleLabel($a->role),
            'external_name'    => $a->external_name,
            'external_org'     => $a->external_org,
            'attended'         => $a->attended,
            'apology_received' => $a->apology_received,
            'notes'            => $a->notes,
        ]);

        $minutes = $hearing->minutes->map(fn($m) => [
            'id'           => $m->id,
            'minute_type'  => $m->minute_type,
            'type_label'   => DisciplinaryHearingMinute::minuteTypeLabel($m->minute_type),
            'sequence'     => $m->sequence,
            'speaker_name' => $m->speaker_name,
            'content'      => $m->content,
            'minute_time'  => $m->minute_time?->format('H:i'),
            'recorded_by'  => $this->personName($m->recorder),
        ]);

        $outcome = $hearing->outcome ? [
            'id'              => $hearing->outcome->id,
            'verdict'         => $hearing->outcome->verdict,
            'verdict_color'   => $hearing->outcome->verdictColor(),
            'outcome'         => $hearing->outcome->outcome?->name,
            'sanction'        => $hearing->outcome->sanction?->name,
            'reasoning'       => $hearing->outcome->reasoning,
            'sanction_detail' => $hearing->outcome->sanction_detail,
            'effective_date'  => $hearing->outcome->effective_date?->format('d M Y'),
            'appeal_deadline' => $hearing->outcome->appeal_deadline?->format('d M Y'),
            'appeal_lodged'   => $hearing->outcome->appeal_lodged,
            'decided_at'      => $hearing->outcome->decided_at?->format('d M Y H:i'),
        ] : null;

        return Inertia::render('Modules/Disciplinary/Hearings/Show', [
            'hearing' => [
                'id'                 => $hearing->id,
                'hearing_number'     => $hearing->hearing_number,
                'case_id'            => $hearing->case_id,
                'case_number'        => $hearing->case?->case_number,
                'case_title'         => $hearing->case?->title,
                'employee_name'      => $hearing->case?->employeeName(),
                'category'           => $hearing->case?->category?->name,
                'chairperson_id'     => $hearing->chairperson_id,
                'chairperson_name'   => $this->personName($hearing->chairperson),
                'initiator_name'     => $this->personName($hearing->initiator),
                'hr_rep_name'        => $this->personName($hearing->hrRep),
                'status'             => $hearing->status,
                'status_color'       => $hearing->statusColor(),
                'hearing_type'       => $hearing->hearing_type,
                'scheduled_at'       => $hearing->scheduled_at?->toIso8601String(),
                'venue'              => $hearing->venue,
                'venue_type'         => $hearing->venue_type,
                'meeting_link'       => $hearing->meeting_link,
                'duration_minutes'   => $hearing->duration_minutes,
                'notice_issued'      => $hearing->notice_issued,
                'notice_issued_date' => $hearing->notice_issued_date?->format('d M Y'),
                'pack_generated'     => $hearing->pack_generated,
                'adjournment_reason' => $hearing->adjournment_reason,
                'adjourned_to'       => $hearing->adjourned_to?->toIso8601String(),
            ],
            'attendees'  => $attendees,
            'minutes'    => $minutes,
            'outcome'    => $outcome,
            'outcomes'   => DisciplinaryOutcome::where('active', true)->orderBy('name')->get(['id', 'name', 'requires_sanction']),
            'sanctions'  => DisciplinarySanction::where('active', true)->orderBy('name')->get(['id', 'name']),
            'employees'  => $this->isDiscAdmin() ? $this->employeeList() : collect(),
            'canEdit'    => $this->isDiscAdmin(),
            'isChair'    => $hearing->chairperson_id === $userId,
        ]);
    }

    // ── Schedule a hearing ────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'case_id'        => 'required|integer|exists:disciplinary_cases,id',
            'chairperson_id' => 'nullable|exists:users,id',
            'initiator_id'   => 'nullable|exists:users,id',
            'hr_rep_id'      => 'nullable|exists:users,id',
            'hearing_type'   => 'required|in:formal,informal,appeal',
            'scheduled_at'   => 'required|date',
            'venue'          => 'nullable|string|max:200',
            'venue_type'     => 'required|in:physical,virtual,hybrid',
            'meeting_link'   => 'nullable|url|max:500',
        ]);

        $case = DisciplinaryCase::find($data['case_id']);

        // Policy check before creating the hearing
        $tempHearing = new DisciplinaryHearing(array_merge($data, ['status' => 'scheduled']));
        $tempHearing->case_id = $data['case_id'];
        $tempHearing->setRelation('case', $case?->load('category'));
        if ($data['scheduled_at'] ?? null) {
            $tempHearing->scheduled_at = \Carbon\Carbon::parse($data['scheduled_at']);
        }
        $this->policyGuard->forHearingScheduled($tempHearing, auth()->id());

        $hearing = DisciplinaryHearing::create(array_merge($data, [
            'status'     => 'scheduled',
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]));

        // Auto-add key roles as attendees
        $roleMap = [
            'employee'    => $case?->employee_id,
            'chairperson' => $data['chairperson_id'] ?? null,
            'initiator'   => $data['initiator_id'] ?? null,
            'hr_rep'      => $data['hr_rep_id'] ?? null,
        ];
        foreach ($roleMap as $role => $userId) {
            if ($userId) {
                DisciplinaryHearingAttendee::create(['hearing_id' => $hearing->id, 'user_id' => $userId, 'role' => $role]);
            }
        }

        // Load relationships needed for notifications
        $hearing->load(['case.investigations.investigator']);

        // Notify all involved parties
        $hearing->refresh();
        $notified  = [];
        $notify    = function (?int $userId, string $role) use ($hearing, &$notified) {
            if (! $userId || in_array($userId, $notified)) return;
            $user = \App\Core\User\Models\User::find($userId);
            $user?->notify(new DisciplinaryHearingScheduledNotification($hearing, $role));
            $notified[] = $userId;
        };

        $notify($case?->employee_id,              'employee');
        $notify($data['chairperson_id'] ?? null,  'chairperson');
        $notify($data['hr_rep_id'] ?? null,       'hr_rep');
        $notify($data['initiator_id'] ?? null,    'attendee');

        // Notify each case investigator
        foreach ($hearing->case?->investigations ?? [] as $inv) {
            $notify($inv->investigator_id, 'investigator');
        }

        activity('disciplinary')
            ->performedOn($hearing->case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'hearing_scheduled', 'hearing_number' => $hearing->hearing_number])
            ->log("Hearing {$hearing->hearing_number} scheduled.");

        return redirect()->route('disciplinary.hearings.show', $hearing)
            ->with('success', "Hearing {$hearing->hearing_number} scheduled.");
    }

    // ── Update hearing details ────────────────────────────────────────────────

    public function update(Request $request, DisciplinaryHearing $hearing): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'status'             => 'nullable|in:scheduled,in_progress,adjourned,completed,cancelled',
            'scheduled_at'       => 'nullable|date',
            'venue'              => 'nullable|string|max:200',
            'venue_type'         => 'nullable|in:physical,virtual,hybrid',
            'meeting_link'       => 'nullable|url|max:500',
            'duration_minutes'   => 'nullable|integer',
            'adjournment_reason' => 'nullable|string',
            'adjourned_to'       => 'nullable|date',
        ]);

        $hearing->update(array_merge($data, ['updated_by' => auth()->id()]));

        return back()->with('success', 'Hearing updated.');
    }

    // ── Mark notice as issued ─────────────────────────────────────────────────

    public function issueNotice(DisciplinaryHearing $hearing): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $hearing->update(['notice_issued' => true, 'notice_issued_date' => now()->toDateString()]);

        // Re-notify the employee that the formal notice has been issued
        $hearing->load(['case']);
        $employeeId = $hearing->case?->employee_id;
        if ($employeeId) {
            $employee = \App\Core\User\Models\User::find($employeeId);
            $employee?->notify(new DisciplinaryHearingScheduledNotification($hearing->fresh(), 'employee'));
        }

        activity('disciplinary')
            ->performedOn($hearing->case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'notice_issued'])
            ->log("Hearing notice issued for {$hearing->hearing_number}.");

        return back()->with('success', 'Notice marked as issued.');
    }

    // ── Generate hearing pack via AI ──────────────────────────────────────────

    public function generatePack(DisciplinaryHearing $hearing): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->evidenceAi->generateHearingPack($hearing->load(['case'])->case);
        $hearing->update(['pack_generated' => true]);

        return back()->with('success', 'Hearing pack generated.');
    }

    // ── Download hearing pack ─────────────────────────────────────────────────

    public function downloadPack(DisciplinaryHearing $hearing): \Illuminate\Http\Response
    {
        $userId = auth()->id();
        abort_unless($this->isDiscAdmin() || $hearing->chairperson_id === $userId || $hearing->hr_rep_id === $userId, 403);

        $report = DisciplinaryAiEvidenceReport::where('case_id', $hearing->case_id)
            ->where('report_type', 'hearing_pack')
            ->latest()
            ->firstOrFail();

        abort_unless($report->hasBytes(), 404);

        // HTML again, and inert for the same reason as the investigation report.
        return $report->fileResponse($report->original_name ?? 'hearing_pack.html', $report->mime);
    }

    // ── Add minute entry ──────────────────────────────────────────────────────

    public function addMinute(Request $request, DisciplinaryHearing $hearing): RedirectResponse
    {
        $userId = auth()->id();
        abort_unless($this->isDiscAdmin() || $hearing->chairperson_id === $userId || $hearing->hr_rep_id === $userId, 403);

        $data = $request->validate([
            'minute_type'  => 'required|in:opening,charge_reading,plea,evidence,witness_testimony,closing,procedural,general',
            'speaker_name' => 'nullable|string|max:150',
            'content'      => 'required|string',
            'minute_time'  => 'nullable|date_format:H:i',
        ]);

        $nextSeq = DisciplinaryHearingMinute::where('hearing_id', $hearing->id)->max('sequence') + 1;

        DisciplinaryHearingMinute::create([
            'hearing_id'   => $hearing->id,
            'sequence'     => $nextSeq,
            'recorded_by'  => $userId,
        ] + $data);

        return back()->with('success', 'Minute recorded.');
    }

    // ── Add attendee ──────────────────────────────────────────────────────────

    public function addAttendee(Request $request, DisciplinaryHearing $hearing): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'user_id'       => 'nullable|exists:users,id',
            'role'          => 'required|in:employee,employee_rep,chairperson,initiator,hr_rep,witness,observer',
            'external_name' => 'nullable|string|max:150',
            'external_org'  => 'nullable|string|max:150',
        ]);

        DisciplinaryHearingAttendee::create(['hearing_id' => $hearing->id] + $data);

        return back()->with('success', 'Attendee added.');
    }

    // ── Mark attendance ───────────────────────────────────────────────────────

    public function markAttendance(Request $request, DisciplinaryHearing $hearing): RedirectResponse
    {
        $userId = auth()->id();
        abort_unless($this->isDiscAdmin() || $hearing->chairperson_id === $userId, 403);

        $data = $request->validate([
            'attendees'                   => 'required|array',
            'attendees.*.id'              => 'required|exists:disciplinary_hearing_attendees,id',
            'attendees.*.attended'        => 'boolean',
            'attendees.*.apology_received'=> 'boolean',
        ]);

        foreach ($data['attendees'] as $att) {
            DisciplinaryHearingAttendee::where('id', $att['id'])
                ->where('hearing_id', $hearing->id)
                ->update([
                    'attended'         => $att['attended'] ?? false,
                    'apology_received' => $att['apology_received'] ?? false,
                ]);
        }

        return back()->with('success', 'Attendance updated.');
    }

    // ── Capture hearing outcome ───────────────────────────────────────────────

    public function captureOutcome(Request $request, DisciplinaryHearing $hearing): RedirectResponse
    {
        $userId = auth()->id();
        abort_unless($this->isDiscAdmin() || $hearing->chairperson_id === $userId, 403);

        $data = $request->validate([
            'verdict'        => 'required|in:guilty,not_guilty,inconclusive',
            'outcome_id'     => 'nullable|exists:disciplinary_outcomes,id',
            'sanction_id'    => 'nullable|exists:disciplinary_sanctions,id',
            'reasoning'      => 'required|string',
            'sanction_detail'=> 'nullable|string',
            'effective_date' => 'nullable|date',
            'appeal_deadline'=> 'nullable|date',
        ]);

        $outcome = DisciplinaryHearingOutcome::updateOrCreate(
            ['hearing_id' => $hearing->id],
            array_merge($data, ['decided_by' => $userId, 'decided_at' => now()])
        );

        // Policy check on outcome (e.g. dismissal requires executive approval)
        $this->policyGuard->forOutcomeCaptured($outcome->fresh(['hearing.case.category', 'sanction', 'outcome']), $userId);

        $hearing->update(['status' => 'completed', 'updated_by' => $userId]);

        activity('disciplinary')
            ->performedOn($hearing->case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'hearing_outcome_captured', 'verdict' => $data['verdict']])
            ->log("Hearing {$hearing->hearing_number} outcome captured: {$data['verdict']}.");

        return back()->with('success', 'Hearing outcome captured.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function summarise(DisciplinaryHearing $h): array
    {
        return [
            'id'             => $h->id,
            'hearing_number' => $h->hearing_number,
            'case_number'    => $h->case?->case_number,
            'employee_name'  => $h->case?->employeeName() ?? '—',
            'chairperson'    => $this->personName($h->chairperson),
            'status'         => $h->status,
            'status_color'   => $h->statusColor(),
            'hearing_type'   => $h->hearing_type,
            'scheduled_at'   => $h->scheduled_at?->format('d M Y H:i'),
            'venue'          => $h->venue,
            'notice_issued'  => $h->notice_issued,
            'pack_generated' => $h->pack_generated,
        ];
    }

    private function personName(?User $user): string
    {
        if (! $user) return '—';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? '—');
    }

    private function employeeList(): \Illuminate\Support\Collection
    {
        return User::join('hr_people as p', 'p.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email']);
    }
}
