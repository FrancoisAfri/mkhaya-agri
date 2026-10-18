<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\HazardService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hazard reporting and triage (§5.2).
 *
 * Reporting is the lowest-friction path in the module — one field is genuinely enough (SHEQ-HAZ-1
 * asks for three taps: camera, describe, submit). Everything else is optional, because the person
 * reporting a missing handrail is usually standing next to it holding a phone.
 */
class HazardController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly HazardService $hazards) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $now = Carbon::now();

        $rows = $companyId === null ? collect() : DB::table('sheq_hazards as h')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'h.site_id')
            ->where('h.company_id', $companyId)->whereNull('h.deleted_at')
            ->orderByRaw("case h.status when 'OPEN' then 0 when 'TRIAGED' then 1 else 2 end")
            ->orderBy('h.triage_due_at')
            ->limit(300)
            ->get(['h.uuid', 'h.reference', 'h.description', 'h.hazard_category', 'h.severity_est',
                'h.status', 'h.reported_at', 'h.triage_due_at', 'h.triaged_at', 'h.rejection_reason',
                'h.location_text', 's.name as site_name']);

        $hazards = $rows->map(fn ($h) => [
            'uuid' => $h->uuid,
            'reference' => $h->reference,
            'description' => $h->description,
            'category' => $h->hazard_category,
            'severity' => $h->severity_est,
            'status' => $h->status,
            'site_name' => $h->site_name,
            'location' => $h->location_text,
            'reported_at' => $h->reported_at,
            'triage_due_at' => $h->triage_due_at,
            // Overdue triage is computed, not stored — a hazard cannot look on-time because a job
            // failed to run (same reasoning as the statutory obligations queue).
            'triage_overdue' => $h->status === 'OPEN' && $h->triage_due_at
                && Carbon::parse($h->triage_due_at)->lt($now),
            'rejection_reason' => $h->rejection_reason,
        ]);

        return Inertia::render('Modules/Sheq/Hazards/Index', [
            'hazards' => $hazards->values(),
            'noCompany' => $companyId === null,
            'summary' => [
                'open' => $hazards->where('status', 'OPEN')->count(),
                'triage_overdue' => $hazards->where('triage_overdue', true)->count(),
                'controlled' => $hazards->where('status', 'CONTROLLED')->count(),
                'rejected' => $hazards->where('status', 'REJECTED')->count(),
            ],
            /*
             * Incidents a hazard could turn out to be (SHEQ-HAZ-4).
             *
             * Without this the two stay separate records of one event: the hazard is closed as
             * "reported" and the incident carries no sign that somebody had flagged the risk
             * beforehand — which is exactly the fact an investigation most wants.
             *
             * Recent and still open: linking to an incident closed last year is almost always a
             * mis-click, and a list of every incident ever is unusable.
             */
            'linkableIncidents' => $companyId === null ? [] : DB::table('sheq_incidents')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['CLOSED', 'REJECTED'])
                ->orderByDesc('occurred_at')->limit(100)
                ->get(['id', 'reference', 'incident_type', 'occurred_at'])
                ->map(fn ($i) => [
                    'id' => (int) $i->id,
                    'label' => trim("{$i->reference} · {$i->incident_type}"),
                ])->all(),
            'can' => [
                'report' => (bool) $request->user()?->can('sheq.hazard.report'),
                'triage' => (bool) $request->user()?->can('sheq.hazard.triage'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Modules/Sheq/Hazards/Create', [
            'sites' => DB::table('sheq_sites')->where('company_id', $this->companyId($request) ?? 0)
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => ['MECHANICAL', 'ELECTRICAL', 'CHEMICAL', 'ERGONOMIC', 'BIOLOGICAL',
                'PSYCHOSOCIAL', 'ENVIRONMENTAL', 'BEHAVIOURAL'],
            'allowAnonymous' => (bool) (DB::table('sheq_settings')
                ->where('company_id', $this->companyId($request) ?? 0)->value('allow_anonymous_reporting') ?? true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403, 'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $validated = $request->validate([
            'description' => ['required', 'string', 'min:3'],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')],
            'hazard_category' => ['nullable', 'string', 'max:30'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'severity_est' => ['nullable', 'integer', 'between:1,5'],
            'is_anonymous' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'uuid'],
        ]);

        $result = $this->hazards->report($this->requireCompany($request), $validated, $request->user()?->id);

        return redirect()->route('sheq.hazards.index')
            ->with('success', "Hazard {$result['reference']} reported. You will be told what happens to it.");
    }

    /**
     * Triage. The reason field is required for a rejection by the service, not merely by the form —
     * an API caller cannot bypass it either (SHEQ-HAZ-3).
     */
    public function triage(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.triage'), 403, 'ENTITLEMENT_REQUIRED: sheq.hazard.triage');

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['ACTION', 'CONTROLLED', 'REJECTED'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'action_title' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'priority' => ['nullable', Rule::in(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'])],
            'due_date' => ['nullable', 'date'],
            'hierarchy_level' => ['nullable', Rule::in(['ELIMINATION', 'SUBSTITUTION', 'ENGINEERING',
                'ADMINISTRATIVE', 'PPE'])],
        ]);

        $result = $this->hazards->triage(
            $this->requireCompany($request), $uuid, $validated['outcome'], $request->user()?->id, $validated
        );

        return back()->with('success', $result['action']
            ? "Triaged — action {$result['action']['reference']} raised."
            : 'Hazard triaged.');
    }
}
