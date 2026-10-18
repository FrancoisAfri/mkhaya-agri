<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Compliance\Events\DataBreachReported;
use App\Core\Compliance\Models\DataBreachIncident;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DataBreachController extends Controller
{
    public function index(Request $request): Response
    {
        $query = DataBreachIncident::with(['reportedBy.person', 'assignedTo.person'])
            ->orderByDesc('detected_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        $incidents = $query->paginate(20)->through(fn ($i) => [
            'uuid'                    => $i->uuid,
            'title'                   => $i->title,
            'severity'                => $i->severity,
            'status'                  => $i->status,
            'detected_at'             => $i->detected_at->toISOString(),
            'notified_regulator_at'   => $i->notified_regulator_at?->toISOString(),
            'affected_record_count'   => $i->affected_record_count,
            'hours_until_deadline'    => $i->hoursUntilDeadline(),
            'is_overdue'              => $i->isOverdue(),
            'reported_by'             => $i->reportedBy?->name,
            'assigned_to'             => $i->assignedTo?->name,
            'created_at'              => $i->created_at->toISOString(),
        ]);

        return Inertia::render('Modules/Compliance/Breaches/Index', [
            'incidents' => $incidents,
            'filters'   => $request->only(['status', 'severity']),
            'agents'    => User::where('status', 1)->with('person:id,user_id,first_name,surname')->get(['id'])->sortBy('name')->values()->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'                  => ['required', 'string', 'max:255'],
            'description'            => ['nullable', 'string'],
            'severity'               => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'detected_at'            => ['required', 'date'],
            'affected_record_count'  => ['nullable', 'integer', 'min:0'],
            'affected_subject_types' => ['nullable', 'array'],
            'affected_subject_types.*' => ['string'],
            'assigned_to'            => ['nullable', 'exists:users,id'],
        ]);

        $incident = DataBreachIncident::create([
            ...$data,
            'reported_by' => auth()->id(),
            'status'      => 'open',
        ]);

        event(new DataBreachReported($incident));

        return redirect()->route('compliance.breaches.show', $incident->uuid)
            ->with('success', 'Breach incident reported. Information Officer(s) have been notified.');
    }

    public function show(DataBreachIncident $incident): Response
    {
        $incident->load(['reportedBy.person', 'assignedTo.person']);

        return Inertia::render('Modules/Compliance/Breaches/Show', [
            'incident' => [
                'uuid'                    => $incident->uuid,
                'title'                   => $incident->title,
                'description'             => $incident->description,
                'severity'                => $incident->severity,
                'status'                  => $incident->status,
                'detected_at'             => $incident->detected_at->toISOString(),
                'notified_regulator_at'   => $incident->notified_regulator_at?->toISOString(),
                'notified_subjects_at'    => $incident->notified_subjects_at?->toISOString(),
                'contained_at'            => $incident->contained_at?->toISOString(),
                'closed_at'               => $incident->closed_at?->toISOString(),
                'affected_record_count'   => $incident->affected_record_count,
                'affected_subject_types'  => $incident->affected_subject_types ?? [],
                'remediation_notes'       => $incident->remediation_notes,
                'regulator_reference'     => $incident->regulator_reference,
                'hours_until_deadline'    => $incident->hoursUntilDeadline(),
                'is_overdue'              => $incident->isOverdue(),
                'reported_by'             => $incident->reportedBy ? ['id' => $incident->reportedBy->id, 'name' => $incident->reportedBy->name] : null,
                'assigned_to'             => $incident->assignedTo ? ['id' => $incident->assignedTo->id, 'name' => $incident->assignedTo->name] : null,
                'created_at'              => $incident->created_at->toISOString(),
                'updated_at'              => $incident->updated_at->toISOString(),
            ],
            'agents'   => User::where('status', 1)->with('person:id,user_id,first_name,surname')->get(['id'])->sortBy('name')->values()->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function updateStatus(Request $request, DataBreachIncident $incident): RedirectResponse
    {
        $data = $request->validate([
            'status'               => ['required', Rule::in(['open', 'notified', 'contained', 'closed'])],
            'remediation_notes'    => ['nullable', 'string'],
            'regulator_reference'  => ['nullable', 'string', 'max:255'],
            'assigned_to'          => ['nullable', 'exists:users,id'],
        ]);

        $timestamps = match ($data['status']) {
            'notified'  => ['notified_regulator_at' => now()],
            'contained' => ['contained_at' => now()],
            'closed'    => ['closed_at' => now()],
            default     => [],
        };

        $incident->update(array_merge($data, $timestamps));

        return back()->with('success', 'Incident status updated.');
    }
}
