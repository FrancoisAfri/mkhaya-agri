<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\FileService;
use App\Modules\Operations\Sheq\Services\InvestigationService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Investigating incidents (SHEQ-INC-7 … SHEQ-INC-10).
 *
 * The service has enforced the rules since it was written; this is the surface that lets anyone
 * reach them. Every guard stays in the service — the controller validates shape and nothing else,
 * so the API, a field device and this screen all meet the same refusals.
 */
class InvestigationController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly InvestigationService $investigations,
        private readonly FileService $files,
    ) {}

    /** The queue: what is open, what is waiting on approval, what is blocked from closing. */
    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Investigations/Index', [
            'noCompany' => $companyId === null,
            'canInvestigate' => (bool) $request->user()?->can('sheq.incident.investigate'),
            'rows' => $companyId === null ? [] : DB::table('sheq_incidents as i')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
                ->leftJoin('sheq_investigations as v', function ($join) {
                    $join->on('v.incident_id', '=', 'i.id')
                        ->whereIn('v.status', ['DRAFT', 'PENDING_APPROVAL', 'APPROVED']);
                })
                ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
                ->whereNotIn('i.status', ['CLOSED', 'REJECTED'])
                ->orderByDesc('i.potential_severity')->orderByDesc('i.occurred_at')
                ->limit(150)
                ->get([
                    'i.uuid', 'i.reference', 'i.occurred_at', 'i.incident_type', 'i.status',
                    'i.actual_severity', 'i.potential_severity', 'i.statutory_category',
                    's.name as site_name',
                    'v.uuid as investigation_uuid', 'v.status as investigation_status',
                    'v.method', 'v.revision',
                ])
                ->map(fn ($r) => (array) $r)->values(),
        ]);
    }

    /** The working screen for one investigation. */
    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);
        $investigation = $this->investigations->view($companyId, $uuid);

        $incidentUuid = $investigation['incident']['uuid'] ?? null;

        return Inertia::render('Modules/Sheq/Investigations/Show', [
            'investigation' => $investigation,
            'history' => $incidentUuid === null ? []
                : $this->investigations->history($companyId, $incidentUuid),
            // Shown while the investigation is still being written, so the investigator knows what
            // will stop closure before they get there.
            'blockers' => $incidentUuid === null ? []
                : $this->investigations->closureBlockers($companyId, $incidentUuid),
            'evidence' => $this->files->forSubject($companyId, 'INCIDENT',
                (int) ($investigation['incident']['id'] ?? 0)),
            'canInvestigate' => (bool) $request->user()?->can('sheq.incident.investigate'),
            'canApprove' => (bool) $request->user()?->can('sheq.incident.classify'),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.incident.investigate');

        $validated = $request->validate([
            'incident_uuid' => ['required', 'uuid'],
            'method' => ['nullable', Rule::in(['FIVE_WHY', 'ICAM', 'FISHBONE', 'BOWTIE', 'TAPROOT'])],
        ]);

        $investigation = $this->investigations->open($companyId, $validated['incident_uuid'],
            $validated['method'] ?? null, $request->user()?->id);

        return redirect("/sheq/investigations/{$investigation['uuid']}")->with('success',
            "Investigation opened. The pack requires {$investigation['required_method']} for this "
            .'potential severity.');
    }

    public function addCause(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.incident.investigate');

        $validated = $request->validate([
            'category' => ['required', Rule::in([
                'ABSENT_FAILED_DEFENCE', 'INDIVIDUAL_TEAM_ACTION', 'TASK_ENVIRONMENT', 'ORGANISATIONAL',
            ])],
            'cause_type' => ['required', Rule::in(['ROOT', 'CONTRIBUTING'])],
            'description' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'string', 'max:2000'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $this->investigations->addCause($companyId, $uuid, $validated, $request->user()?->id);

        return back()->with('success', 'Cause recorded.');
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.incident.investigate');

        $validated = $request->validate([
            'summary' => ['nullable', 'string', 'max:5000'],
            'sequence_of_events' => ['nullable', 'string', 'max:10000'],
            'method_payload' => ['nullable', 'array'],
        ]);

        $this->investigations->update($companyId, $uuid, $validated, $request->user()?->id);

        return back()->with('success', 'Investigation saved.');
    }

    public function submit(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.incident.investigate');

        $this->investigations->submit($companyId, $uuid, $request->user()?->id);

        return back()->with('success', 'Submitted for approval.');
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        // A different permission from investigating, because the point of approval is that somebody
        // other than the author agrees (SHEQ-CAP-2's principle, applied to findings).
        $this->authorise($request, 'sheq.incident.classify');

        $this->investigations->approve($companyId, $uuid, $request->user()?->id);

        return back()->with('success', 'Findings approved.');
    }

    public function closeIncident(Request $request, string $incidentUuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.incident.classify');

        $this->investigations->closeIncident($companyId, $incidentUuid, $request->user()?->id);

        return redirect('/sheq/investigations')->with('success', 'Incident closed.');
    }

    public function reopen(Request $request, string $incidentUuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.incident.classify');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $investigation = $this->investigations->reopen($companyId, $incidentUuid,
            $validated['reason'], $request->user()?->id);

        return redirect("/sheq/investigations/{$investigation['uuid']}")->with('success',
            "Revision {$investigation['revision']} opened. The previous one is preserved unchanged.");
    }

    private function authorise(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403,
            "ENTITLEMENT_REQUIRED: {$permission}");
    }
}
