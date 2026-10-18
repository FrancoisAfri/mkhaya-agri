<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\EvidenceLedger;
use App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy;
use App\Modules\Operations\ProjectManagement\Support\ProjectPresenter;
use App\Modules\Operations\ProjectManagement\Support\Timezones;
use App\Support\Money\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Evidence Ledger's read and write surface.
 *
 * Note what is absent: there is no update and no destroy. The ledger does not support them
 * (PM-ADR-002), so offering routes that would only ever fail would be dishonest. A mistake is
 * corrected by `supersede`, which writes a new event and leaves the original visible.
 */
class EvidenceController extends Controller
{
    public function __construct(private readonly EvidenceLedger $ledger)
    {
    }

    /** The timeline — what happened, when, who said so, and how confident we are. */
    public function timeline(Request $request): Response
    {
        $this->authorize('viewAny', EvidenceEvent::class);

        $filters = (array) $request->input('filter', []);

        $query = EvidenceEvent::query();

        if (! empty($filters['project_id'])) {
            $project = Project::where('uuid', $filters['project_id'])->firstOrFail();
            $query->where('project_id', $project->id);
        }

        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        // Superseded events are hidden by default but reachable — the correction history is the
        // point of keeping them.
        if (empty($filters['include_superseded'])) {
            $query->live();
        }

        $events = $query->orderByDesc('occurred_at')->limit(200)->get();

        return Inertia::render('Modules/ProjectManagement/Evidence/Timeline', [
            'events'      => $events->map(fn (EvidenceEvent $e) => ProjectPresenter::evidence($e))->all(),
            'filters'      => $filters,
            // Grouped by domain: 38 flat options is a wall, eight labelled groups is a menu.
            'eventTypes'   => collect(EvidenceTaxonomy::types())
                ->map(fn (string $type) => ['value' => $type, 'domain' => EvidenceTaxonomy::domainOf($type)])
                ->values()->all(),
            'subjectTypes' => EvidenceEvent::SUBJECT_TYPES,
            'currencies'   => Currencies::options(),
            'timezones'    => Timezones::options(),
            'projects'    => Project::orderBy('name')->get(['uuid', 'name', 'code'])
                ->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->name, 'code' => $p->code]),
            'can'         => [
                'record' => $request->user()->can('record', EvidenceEvent::class),
                'verify' => $request->user()->can('verify', EvidenceEvent::class),
            ],
        ]);
    }

    /** Record an observation. This is the field worker's core action. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('record', EvidenceEvent::class);

        $data = $request->validate([
            // Client-generated ids and idempotency keys are what make offline sync safe to retry
            // (PM-ADR-004). Both are optional for a browser, required in practice for a device.
            'uuid'            => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
            'project_id'      => ['required', 'uuid', Rule::exists('pm_projects', 'uuid')->whereNull('deleted_at')],
            'subject_type'    => ['required', Rule::in(EvidenceEvent::SUBJECT_TYPES)],
            'subject_id'      => ['nullable', 'uuid'],
            'event_type'      => ['required', 'string', 'max:60'],
            'occurred_at'     => ['required', 'date'],
            'occurred_tz'     => ['required', 'timezone'],
            'quantity'        => ['nullable', 'numeric'],
            'unit'            => ['nullable', 'string', 'max:20'],
            // A decimal string, and its currency alongside it — never a bare number (PM-ADR-006).
            'amount'          => ['nullable', 'string', 'regex:/^-?\d+(\.\d{1,2})?$/', 'required_with:currency'],
            'currency'        => ['nullable', 'string', 'size:3', 'required_with:amount', Rule::in(Currencies::codes())],
            'confidence'      => ['nullable', 'numeric', 'between:0,1'],
            'geo_lat'         => ['nullable', 'numeric', 'between:-90,90'],
            'geo_lng'         => ['nullable', 'numeric', 'between:-180,180'],
            'payload'         => ['nullable', 'array'],
            'attachments'     => ['nullable', 'array'],
        ]);

        $project = Project::where('uuid', $data['project_id'])->firstOrFail();

        $this->ledger->append([
            ...$data,
            'project_id'    => $project->id,
            'subject_uuid'  => $data['subject_id'] ?? null,
            'subject_id'    => $data['subject_type'] === 'project' ? $project->id : null,
            'source_module' => 'pm',
            'actor_type'    => 'user',
            'actor_id'      => (string) Auth::id(),
        ]);

        return back()->with('success', __('project_management.flash.evidence_recorded'));
    }

    /**
     * Correct an earlier observation. The original stays in the ledger, pointed at its replacement.
     */
    public function supersede(Request $request, string $uuid): RedirectResponse
    {
        $original = EvidenceEvent::where('uuid', $uuid)->firstOrFail();

        $this->authorize('supersede', $original);

        $data = $request->validate([
            'reason'     => ['required', 'string', 'max:500'],
            'quantity'   => ['nullable', 'numeric'],
            'unit'       => ['nullable', 'string', 'max:20'],
            'amount'     => ['nullable', 'string', 'regex:/^-?\d+(\.\d{1,2})?$/', 'required_with:currency'],
            'currency'   => ['nullable', 'string', 'size:3', 'required_with:amount', Rule::in(Currencies::codes())],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $this->ledger->supersede($original, [
            ...array_diff_key($data, ['reason' => null]),
            'actor_type' => 'user',
            'actor_id'   => (string) Auth::id(),
            'payload'    => ['reason' => $data['reason']],
        ]);

        return back()->with('success', __('project_management.flash.evidence_superseded'));
    }

    /**
     * The chain audit: walk a project's evidence and confirm nothing has been altered. Linear in
     * the project's event count, so it is an explicit action rather than something the timeline
     * does on every render.
     */
    public function audit(Request $request): Response
    {
        $this->authorize('verify', EvidenceEvent::class);

        $result = null;
        $selected = $request->input('project_id');

        if ($selected) {
            $project = Project::where('uuid', $selected)->firstOrFail();
            $result = $this->ledger->verifyChain($project->company_id, $project->id)->toArray();
        }

        return Inertia::render('Modules/ProjectManagement/Evidence/Audit', [
            'projects' => Project::orderBy('name')->get(['uuid', 'name', 'code'])
                ->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->name, 'code' => $p->code]),
            'selected' => $selected,
            'result'   => $result,
        ]);
    }
}
