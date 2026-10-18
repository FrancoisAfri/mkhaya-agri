<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Notifications\DisciplinaryIncidentAssignedNotification;
use App\Modules\Disciplinary\Notifications\DisciplinaryIncidentReportedNotification;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Support\Facades\Notification;
use App\Modules\Disciplinary\Models\DisciplinaryAccused;
use App\Modules\Disciplinary\Models\DisciplinaryCategory;
use App\Modules\Disciplinary\Models\DisciplinaryEvidence;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use App\Modules\Disciplinary\Models\DisciplinaryWitness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryIncidentController extends Controller
{
    use DisciplinaryAuthTrait, FileStorage;

    public function index(): Response
    {
        $incidents = $this->scopedIncidentQuery()
            ->with(['reporter', 'category', 'investigator'])
            ->orderByDesc('incident_date')
            ->paginate(25)
            ->through(fn($i) => $this->summarise($i));

        return Inertia::render('Modules/Disciplinary/Incidents/Index', [
            'incidents'  => $incidents,
            'categories' => DisciplinaryCategory::where('active', true)->orderBy('name')->get(['id', 'name', 'severity_level']),
            'statuses'   => $this->statusOptions(),
            'positions'  => $this->positionList(),
        ]);
    }

    public function showReport(): Response
    {
        return Inertia::render('Modules/Disciplinary/Incidents/Report', [
            'categories' => DisciplinaryCategory::where('active', true)->orderBy('name')->get(['id', 'name', 'severity_level']),
            'employees'  => $this->employeeList(),
            'positions'  => $this->positionList(),
        ]);
    }

    public function show(DisciplinaryIncident $incident): Response
    {
        $incident->load(['reporter', 'category', 'investigator', 'formalCase', 'witnesses.employee', 'evidence.uploader', 'accused.employee']);

        $role = $this->incidentViewerRole($incident);
        abort_if($role === 'none', 403, 'You do not have access to this incident.');

        $activityLogs = \Spatie\Activitylog\Models\Activity::with('causer.person')
            ->where('subject_type', DisciplinaryIncident::class)
            ->where('subject_id', $incident->id)
            ->latest()
            ->get()
            ->map(fn($l) => [
                'id'          => $l->id,
                'description' => $l->description,
                'properties'  => $l->properties,
                'user'        => $l->causer?->person
                    ? trim("{$l->causer->person->first_name} {$l->causer->person->surname}")
                    : ($l->causer?->email ?? 'System'),
                'created_at'  => $l->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Modules/Disciplinary/Incidents/Show', [
            'incident'     => $this->detail($incident),
            'viewerRole'   => $role,
            'activityLogs' => $activityLogs,
            'employees'    => $this->isDiscAdmin() ? $this->employeeList() : collect(),
            'statuses'     => $this->statusOptions(),
            'positions'    => $this->positionList(),
        ]);
    }

    // ── reportIncident() ──────────────────────────────────────────────────────

    public function report(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'is_anonymous'    => 'boolean',
            'reporter_name'   => 'nullable|string|max:150',
            'reporter_email'  => 'nullable|email|max:150',
            'category_id'     => 'nullable|exists:disciplinary_categories,id',
            'title'           => 'required|string|max:200',
            'description'     => 'required|string',
            'severity_level'  => 'required|in:minor,serious,gross',
            'incident_date'   => 'required|date',
            'location'        => 'nullable|string|max:200',
            'location_details'=> 'nullable|string',
            // Accused (array)
            'accused'                    => 'nullable|array',
            'accused.*.employee_id'      => 'nullable|exists:users,id',
            'accused.*.name'             => 'nullable|string|max:150',
            'accused.*.job_title'        => 'nullable|string|max:150',
            'accused.*.description'      => 'nullable|string',
            // Witnesses (array)
            'witnesses'                  => 'nullable|array',
            'witnesses.*.name'           => 'required_with:witnesses|string|max:150',
            'witnesses.*.email'          => 'nullable|email|max:150',
            'witnesses.*.phone'          => 'nullable|string|max:50',
            'witnesses.*.is_anonymous'   => 'boolean',
            'witnesses.*.employee_id'    => 'nullable|exists:users,id',
        ]);

        $isAnon = $request->boolean('is_anonymous');

        $incident = DisciplinaryIncident::create([
            'reporter_id'     => $isAnon ? null : auth()->id(),
            'is_anonymous'    => $isAnon,
            'reporter_name'   => $isAnon ? ($data['reporter_name'] ?? null) : null,
            'reporter_email'  => $isAnon ? ($data['reporter_email'] ?? null) : null,
            'category_id'     => $data['category_id'] ?? null,
            'title'           => $data['title'],
            'description'     => $data['description'],
            'severity_level'  => $data['severity_level'],
            'incident_date'   => $data['incident_date'],
            'location'        => $data['location'] ?? null,
            'location_details'=> $data['location_details'] ?? null,
            'status'          => 'open',
            'created_by'      => auth()->id(),
            'updated_by'      => auth()->id(),
        ]);

        // Accused
        foreach ($data['accused'] ?? [] as $acc) {
            if (! empty($acc['employee_id']) || ! empty($acc['name'])) {
                DisciplinaryAccused::create(array_merge($acc, ['incident_id' => $incident->id]));
            }
        }

        // Witnesses
        foreach ($data['witnesses'] ?? [] as $wit) {
            DisciplinaryWitness::create(array_merge($wit, ['incident_id' => $incident->id]));
        }

        // Auto-assign investigator
        $this->assignInvestigatorAutomatically($incident);

        // Notify HR and admin users
        $recipients = $this->hrAndAdminUsers();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new DisciplinaryIncidentReportedNotification($incident));
        }

        activity('disciplinary')
            ->performedOn($incident)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'incident_reported', 'number' => $incident->incident_number])
            ->log("Incident {$incident->incident_number} reported.");

        return redirect()->route('disciplinary.incidents.show', $incident)
            ->with('success', "Incident {$incident->incident_number} submitted successfully.");
    }

    // ── uploadEvidence() — admin/HR or investigator ───────────────────────────

    public function uploadEvidence(Request $request, DisciplinaryIncident $incident): RedirectResponse
    {
        $role = $this->incidentViewerRole($incident);
        abort_unless(in_array($role, ['admin_hr', 'investigator']), 403);

        $request->validate([
            'files'          => 'required|array|min:1',
            'files.*'        => 'required|file|max:20480',
            'descriptions'   => 'nullable|array',
            'descriptions.*' => 'nullable|string|max:255',
        ]);

        foreach ($request->file('files') as $index => $file) {
            $stored = $this->saveFileToDatabase($file);

            $evidence = new DisciplinaryEvidence([
                'incident_id'   => $incident->id,
                'uploaded_by'   => auth()->id(),
                'uuid'          => $stored['uuid'],
                'file_name'     => $stored['original_name'],
                'original_name' => $stored['original_name'],
                'mime'          => $stored['mime'],
                'file_type'     => $stored['mime'],
                'file_size'     => $stored['size'],
                'description'   => $request->input("descriptions.{$index}"),
            ]);

            $evidence->writeBytes(base64_decode($stored['data']), $file->getClientOriginalExtension());
            $evidence->save();
        }

        activity('disciplinary')
            ->performedOn($incident)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'evidence_uploaded', 'count' => count($request->file('files'))])
            ->log("Evidence uploaded for incident {$incident->incident_number}.");

        return back()->with('success', 'Evidence uploaded successfully.');
    }

    // ── downloadEvidence() ────────────────────────────────────────────────────

    public function downloadEvidence(DisciplinaryEvidence $evidence): \Illuminate\Http\Response
    {
        $incident = DisciplinaryIncident::findOrFail($evidence->incident_id);
        abort_unless(
            in_array($this->incidentViewerRole($incident), ['admin_hr', 'investigator', 'reporter']),
            403
        );

        abort_if(! $evidence->hasBytes(), 404, 'File not stored in database.');

        return $evidence->fileResponse($evidence->original_name ?? $evidence->file_name, $evidence->mime);
    }

    // ── captureWitnessStatement() — admin/HR or investigator ─────────────────

    public function captureWitnessStatement(Request $request, DisciplinaryIncident $incident): RedirectResponse
    {
        abort_unless(in_array($this->incidentViewerRole($incident), ['admin_hr', 'investigator']), 403);
        $data = $request->validate([
            'witness_id'     => 'required|exists:disciplinary_witnesses,id',
            'statement'      => 'required|string',
            'statement_date' => 'nullable|date',
        ]);

        $witness = DisciplinaryWitness::where('incident_id', $incident->id)->findOrFail($data['witness_id']);
        $witness->update([
            'statement'      => $data['statement'],
            'statement_date' => $data['statement_date'] ?? now(),
        ]);

        activity('disciplinary')
            ->performedOn($incident)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'witness_statement_captured', 'witness' => $witness->name])
            ->log("Witness statement captured: {$witness->name}.");

        return back()->with('success', "Statement for {$witness->name} recorded.");
    }

    public function addWitness(Request $request, DisciplinaryIncident $incident): RedirectResponse
    {
        abort_unless(in_array($this->incidentViewerRole($incident), ['admin_hr', 'investigator']), 403);

        $data = $request->validate([
            'employee_id'  => 'nullable|exists:users,id',
            'name'         => 'required|string|max:150',
            'email'        => 'nullable|email|max:150',
            'phone'        => 'nullable|string|max:50',
            'is_anonymous' => 'boolean',
        ]);

        DisciplinaryWitness::create(array_merge($data, ['incident_id' => $incident->id]));
        return back()->with('success', 'Witness added.');
    }

    public function updateStatus(Request $request, DisciplinaryIncident $incident): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'status'           => 'required|in:open,under_investigation,escalated,closed,dismissed',
            'resolution_notes' => 'nullable|string',
        ]);

        $old = $incident->status;
        $updates = ['status' => $data['status'], 'updated_by' => auth()->id()];

        if (in_array($data['status'], ['closed', 'dismissed'])) {
            $updates['resolved_at']       = now();
            $updates['resolution_notes']  = $data['resolution_notes'] ?? null;
        }

        $incident->update($updates);

        activity('disciplinary')
            ->performedOn($incident)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'status_changed', 'before' => $old, 'after' => $data['status']])
            ->log("Incident status changed from '{$old}' to '{$data['status']}'.");

        return back()->with('success', 'Status updated.');
    }

    public function assignInvestigator(Request $request, DisciplinaryIncident $incident): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['investigator_id' => 'required|exists:users,id']);

        $incident->update([
            'investigator_id'          => $data['investigator_id'],
            'investigator_assigned_at' => now(),
            'auto_assigned'            => false,
            'status'                   => 'under_investigation',
            'updated_by'               => auth()->id(),
        ]);

        $investigator = User::find($data['investigator_id']);
        $name = $this->userName($investigator);
        $investigator?->notify(new DisciplinaryIncidentAssignedNotification($incident->fresh(), false));

        activity('disciplinary')
            ->performedOn($incident)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'investigator_assigned', 'investigator' => $name])
            ->log("Investigator assigned: {$name}.");

        return back()->with('success', "Investigator {$name} assigned.");
    }

    // ── generateCaseNumber() ──────────────────────────────────────────────────
    // Exposed as a utility — also called internally on create via model boot()
    public function generateCaseNumber(): JsonResponse
    {
        $number = 'INC-' . now()->format('Y') . '-' . str_pad(
            DisciplinaryIncident::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT
        );
        return response()->json(['incident_number' => $number]);
    }

    // ── assignInvestigatorAutomatically() ────────────────────────────────────

    private function assignInvestigatorAutomatically(DisciplinaryIncident $incident): void
    {
        // Strategy: find the HR or compliance officer with the fewest open incidents
        $investigator = User::join('model_has_roles as mr', 'mr.model_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->whereIn('r.name', ['HR', 'compliance officer'])
            ->where('users.status', 1)
            ->select('users.id')
            ->selectRaw('(SELECT COUNT(*) FROM disciplinary_incidents WHERE investigator_id = users.id AND status IN (\'open\',\'under_investigation\') AND deleted_at IS NULL) as open_count')
            ->orderBy('open_count')
            ->first();

        if (! $investigator) {
            return;
        }

        $incident->update([
            'investigator_id'          => $investigator->id,
            'investigator_assigned_at' => now(),
            'auto_assigned'            => true,
            'status'                   => 'under_investigation',
        ]);

        $investigatorUser = User::find($investigator->id);
        $investigatorUser?->notify(new DisciplinaryIncidentAssignedNotification($incident->fresh(), true));

        activity('disciplinary')
            ->performedOn($incident)
            ->withProperties(['action' => 'investigator_auto_assigned', 'investigator_id' => $investigator->id])
            ->log("Investigator auto-assigned to incident {$incident->incident_number}.");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function summarise(DisciplinaryIncident $i): array
    {
        return [
            'id'              => $i->id,
            'uuid'            => $i->uuid,
            'incident_number' => $i->incident_number,
            'title'           => $i->title,
            'reporter_name'   => $i->reporterName(),
            'is_anonymous'    => $i->is_anonymous,
            'category'        => $i->category?->name,
            'severity_level'  => $i->severity_level,
            'status'          => $i->status,
            'status_color'    => DisciplinaryIncident::statusColor($i->status),
            'incident_date'   => $i->incident_date?->format('d M Y H:i'),
            'location'        => $i->location,
            'investigator'    => $this->userName($i->investigator),
            'auto_assigned'   => $i->auto_assigned,
            'created_at'      => $i->created_at?->format('d M Y'),
        ];
    }

    private function detail(DisciplinaryIncident $i): array
    {
        return array_merge($this->summarise($i), [
            'description'              => $i->description,
            'location_details'         => $i->location_details,
            'reporter_email'           => $i->reporter_email,
            'investigator_id'          => $i->investigator_id,
            'investigator_assigned_at' => $i->investigator_assigned_at?->format('d M Y H:i'),
            'case_id'                  => $i->case_id,
            'case_number'              => $i->formalCase?->case_number,
            'resolution_notes'         => $i->resolution_notes,
            'resolved_at'              => $i->resolved_at?->format('d M Y H:i'),
            'witnesses'                => $i->witnesses->map(fn($w) => [
                'id'             => $w->id,
                'name'           => $w->name,
                'email'          => $w->email,
                'phone'          => $w->phone,
                'is_anonymous'   => $w->is_anonymous,
                'has_statement'  => ! empty($w->statement),
                'statement'      => $w->statement,
                'statement_date' => $w->statement_date?->format('d M Y'),
            ]),
            'evidence' => $i->evidence->map(fn($e) => [
                'id'          => $e->id,
                'file_name'   => $e->file_name,
                'file_type'   => $e->file_type,
                'file_size'   => $e->humanSize(),
                'description' => $e->description,
                'url'         => $e->url(),
                'uploaded_by' => $this->userName($e->uploader),
                'created_at'  => $e->created_at?->format('d M Y H:i'),
            ]),
            'accused' => $i->accused->map(fn($a) => [
                'id'        => $a->id,
                'name'      => $a->displayName(),
                'job_title' => $a->job_title,
                'description'=> $a->description,
            ]),
        ]);
    }

    private function userName(?User $user): string
    {
        if (! $user) return 'Unknown';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? 'Unknown');
    }

    private function employeeList(): \Illuminate\Support\Collection
    {
        return User::join('hr_people as p', 'p.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email']);
    }

    private function hrAndAdminUsers(): \Illuminate\Support\Collection
    {
        return User::join('model_has_roles as mr', 'mr.model_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->whereIn('r.name', ['admin', 'HR', 'compliance officer'])
            ->where('users.status', 1)
            ->where('users.id', '!=', auth()->id())
            ->select('users.*')
            ->distinct()
            ->get();
    }

    private function positionList(): \Illuminate\Support\Collection
    {
        return HrPosition::where('status', 1)->orderBy('name')->get(['id', 'name']);
    }

    private function statusOptions(): array
    {
        return [
            ['value' => 'open',                'label' => 'Open',                'color' => 'warning'],
            ['value' => 'under_investigation', 'label' => 'Under Investigation', 'color' => 'primary'],
            ['value' => 'escalated',           'label' => 'Escalated',           'color' => 'deep-purple'],
            ['value' => 'closed',              'label' => 'Closed',              'color' => 'success'],
            ['value' => 'dismissed',           'label' => 'Dismissed',           'color' => 'grey'],
        ];
    }
}
