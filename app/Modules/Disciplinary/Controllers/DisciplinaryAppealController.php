<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Modules\Disciplinary\Models\DisciplinaryAppeal;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinarySanction;
use App\Modules\Disciplinary\Services\AppealsService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryAppealController extends Controller
{
    use DisciplinaryAuthTrait, FileStorage;

    public function __construct(private readonly AppealsService $service) {}

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $appeals = DisciplinaryAppeal::with(['employee.person', 'case', 'chairperson.person', 'outcome'])
            ->when($request->status,      fn($q, $s) => $q->where('status', $s))
            ->when($request->employee_id, fn($q, $id)=> $q->where('employee_id', $id))
            ->orderByDesc('submitted_at')
            ->paginate(25)
            ->through(fn($a) => $this->mapAppeal($a));

        $stats = [
            'submitted'     => DisciplinaryAppeal::where('status', 'submitted')->count(),
            'under_review'  => DisciplinaryAppeal::where('status', 'under_review')->count(),
            'hearing_scheduled' => DisciplinaryAppeal::where('status', 'hearing_scheduled')->count(),
            'decided'       => DisciplinaryAppeal::where('status', 'decided')->count(),
            'total'         => DisciplinaryAppeal::count(),
        ];

        return Inertia::render('Modules/Disciplinary/Appeals/Index', [
            'appeals'        => $appeals,
            'stats'          => $stats,
            'filters'        => $request->only('status', 'employee_id'),
            'employees'      => $this->employeeList(),
            'open_cases'     => $this->openCaseList(),
            'open_hearings'  => $this->hearingList(),
        ]);
    }

    // ── Submit ────────────────────────────────────────────────────────────────

    public function submitAppeal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'case_id'              => 'required|exists:disciplinary_cases,id',
            'employee_id'          => 'required|exists:users,id',
            'grounds'              => 'required|string',
            'supporting_argument'  => 'nullable|string',
            'deadline_date'        => 'nullable|date|after:today',
            'hearing_id'           => 'nullable|exists:disciplinary_hearings,id',
        ]);

        $appeal = $this->service->submitAppeal(
            $data['case_id'], $data['employee_id'], auth()->id(),
            $data['grounds'], $data['supporting_argument'] ?? null,
            $data['deadline_date'] ?? null, $data['hearing_id'] ?? null,
        );

        return redirect()->back()->with('success', "Appeal {$appeal->appeal_number} submitted.");
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(DisciplinaryAppeal $appeal): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $appeal->loadMissing([
            'case.category', 'employee.person', 'hearing',
            'appealHearing', 'chairperson.person', 'hrRep.person',
            'evidence', 'outcome.decidedBy.person', 'outcome.newSanction',
        ]);

        $sanctions = DisciplinarySanction::where('active', true)
            ->orderBy('severity_level')->get(['id', 'name', 'severity_level']);

        return Inertia::render('Modules/Disciplinary/Appeals/Show', [
            'appeal'        => $this->mapAppeal($appeal),
            'sanctions'     => $sanctions,
            'employees'     => $this->employeeList(),
            'open_hearings' => $this->hearingList(),
        ]);
    }

    // ── Panel assignment ──────────────────────────────────────────────────────

    public function assignChairperson(Request $request, DisciplinaryAppeal $appeal): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'chairperson_id' => 'required|exists:users,id',
            'hr_rep_id'      => 'nullable|exists:users,id',
        ]);

        $this->service->assignAppealChairperson($appeal, $data['chairperson_id'], $data['hr_rep_id'] ?? null);
        return redirect()->back()->with('success', 'Appeal chairperson assigned.');
    }

    public function scheduleHearing(Request $request, DisciplinaryAppeal $appeal): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['hearing_date' => 'required|date|after:now']);
        $this->service->scheduleAppealHearing($appeal, $data['hearing_date']);
        return redirect()->back()->with('success', 'Appeal hearing scheduled.');
    }

    // ── Evidence ──────────────────────────────────────────────────────────────

    public function captureEvidence(Request $request, DisciplinaryAppeal $appeal): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'file'        => 'required|file|max:20480',
            'description' => 'nullable|string|max:500',
        ]);

        $this->service->captureAppealEvidence(
            $appeal, $request->file('file'),
            $data['description'] ?? '', auth()->id()
        );

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    public function downloadEvidence(\App\Modules\Disciplinary\Models\DisciplinaryAppealEvidence $evidence): HttpResponse
    {
        return $evidence->fileResponse($evidence->original_name, $evidence->mime);
    }

    // ── Pack ──────────────────────────────────────────────────────────────────

    public function generatePack(DisciplinaryAppeal $appeal): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->service->generateAppealPack($appeal);

        $appeal->update(['notes' => ($appeal->notes ?? '') . "\n[Pack generated " . now()->format('d M Y H:i') . ']']);

        return redirect()->back()->with('success', 'Appeal pack generated.');
    }

    // ── Outcome ───────────────────────────────────────────────────────────────

    public function captureOutcome(Request $request, DisciplinaryAppeal $appeal): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'verdict'          => 'required|in:upheld,dismissed,partially_upheld',
            'reasoning'        => 'required|string',
            'new_sanction_id'  => 'nullable|exists:disciplinary_sanctions,id',
            'outcome_notes'    => 'nullable|string',
            'sanction_changed' => 'boolean',
            'case_overturned'  => 'boolean',
            'notify_employee'  => 'boolean',
        ]);

        $outcome = $this->service->captureAppealOutcome(
            $appeal, auth()->id(),
            $data['verdict'], $data['reasoning'],
            $data['new_sanction_id'] ?? null,
            $data['outcome_notes'] ?? null,
            $data['sanction_changed'] ?? false,
            $data['case_overturned'] ?? false,
            $data['notify_employee'] ?? true,
        );

        return redirect()->back()->with('success', "Appeal decided: {$outcome->verdict}");
    }

    public function withdraw(Request $request, DisciplinaryAppeal $appeal): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $this->service->withdrawAppeal($appeal, $data['reason']);
        return redirect()->back()->with('success', 'Appeal withdrawn.');
    }

    // ── Map helper ────────────────────────────────────────────────────────────

    private function mapAppeal(DisciplinaryAppeal $a): array
    {
        return [
            'id'                  => $a->id,
            'appeal_number'       => $a->appeal_number,
            'employee_id'         => $a->employee_id,
            'employee'            => $a->employee->person?->full_name ?? $a->employee?->email,
            'case_id'             => $a->case_id,
            'case_number'         => $a->case?->case_number,
            'case_title'          => $a->case?->title,
            'grounds'             => $a->grounds,
            'supporting_argument' => $a->supporting_argument,
            'deadline_date'       => $a->deadline_date?->format('d M Y'),
            'submitted_at'        => $a->submitted_at?->format('d M Y H:i'),
            'appeal_hearing_date' => $a->appeal_hearing_date?->format('d M Y H:i'),
            'status'              => $a->status,
            'notes'               => $a->notes,
            'chairperson'         => $a->chairperson?->person?->full_name,
            'hr_rep'              => $a->hrRep?->person?->full_name,
            'appeal_hearing'      => $a->appealHearing ? [
                'id'             => $a->appealHearing->id,
                'hearing_number' => $a->appealHearing->hearing_number,
                'scheduled_at'   => $a->appealHearing->scheduled_at?->format('d M Y H:i'),
                'status'         => $a->appealHearing->status,
                'venue'          => $a->appealHearing->venue,
                'venue_type'     => $a->appealHearing->venue_type,
                'meeting_link'   => $a->appealHearing->meeting_link,
            ] : null,
            'evidence_count'      => $a->relationLoaded('evidence') ? $a->evidence->count() : 0,
            'evidence'            => $a->relationLoaded('evidence') ? $a->evidence->map(fn($e) => [
                'id'            => $e->id,
                'original_name' => $e->original_name,
                'description'   => $e->description,
                'uploaded_by'   => $e->uploadedBy?->person?->full_name,
                'download_url'  => route('disciplinary.appeals.evidence.download', $e->id),
            ]) : [],
            'outcome'             => $a->outcome ? [
                'verdict'         => $a->outcome->verdict,
                'reasoning'       => $a->outcome->reasoning,
                'decided_by'      => $a->outcome->decidedBy?->person?->full_name,
                'decided_at'      => $a->outcome->decided_at?->format('d M Y'),
                'sanction_changed'=> $a->outcome->sanction_changed,
                'case_overturned' => $a->outcome->case_overturned,
                'new_sanction'    => $a->outcome->newSanction?->name,
            ] : null,
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

    private function hearingList(): \Illuminate\Support\Collection
    {
        return DisciplinaryHearing::with('case')
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(fn($h) => [
                'id'    => $h->id,
                'label' => "{$h->hearing_number} — {$h->case?->case_number} ({$h->scheduled_at?->format('d M Y')})",
            ]);
    }
}
