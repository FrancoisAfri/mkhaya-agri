<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Services\ObservationService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * What people were seen doing, and what keeps happening (OBS-1 to OBS-4).
 *
 * The register and the trend on one screen, deliberately. A behavioural observation on its own
 * is an anecdote — one person, one morning, one shortcut — and acting on anecdotes is how a
 * safety programme ends up chasing whichever story was told most recently. The value is entirely
 * in the pattern: that the same behaviour has been seen eleven times, mostly at one site, mostly
 * by one contractor. Putting the recurrence report behind a separate menu item would mean the
 * people filing observations never see what their filing produced.
 */
class ObservationController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(private readonly ObservationService $observations) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $days = max(7, min(365, (int) $request->integer('days', 90)));
        $from = Carbon::today()->subDays($days);
        $siteId = $this->permittedSite($request, $companyId);

        return Inertia::render('Modules/Sheq/Registers/Observations', [
            'noCompany' => $companyId === null,
            'days' => $days,
            'siteId' => $siteId,
            'types' => collect(ObservationService::TYPES)
                ->map(fn (array $meta, string $type) => $meta + ['type' => $type])
                ->values()->all(),
            'riskRatings' => ObservationService::RISK_RATINGS,
            'rows' => $companyId === null ? [] : $this->observations->all($companyId, [
                'site_id' => $siteId,
                'type' => $request->query('type'),
                'from' => $from,
            ]),
            'behaviours' => $companyId === null ? []
                : $this->observations->behaviours($companyId),
            'recurrence' => $companyId === null ? null
                : $this->observations->recurrence($companyId, $from, Carbon::now(), $siteId),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'activities' => $companyId === null ? [] : DB::table('sheq_activities')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['code', 'name'])->map(fn ($a) => (array) $a)->all(),
            'contractors' => $companyId === null ? [] : DB::table('sheq_contractors')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($c) => (array) $c)->all(),
            'workers' => $companyId === null ? [] : DB::table('sheq_contractor_workers as w')
                ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
                ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
                ->where('w.is_active', true)->orderBy('c.name')->orderBy('w.name')->limit(1000)
                ->get(['w.id', 'w.name', 'c.name as contractor_name'])
                ->map(fn ($w) => (array) $w)->all(),
            /*
             * The employee roster, from the same directory the incident register uses. One
             * source, so a person who appears in an incident appears here under the same name —
             * there is no `users.name` column in this product and a second implementation of
             * "what is this person called" produces a different answer.
             */
            'people' => $companyId === null ? []
                : app(EmployeeDirectory::class)->roster($companyId),
            'canRecord' => (bool) $request->user()?->can('sheq.hazard.report'),
            'canAction' => (bool) $request->user()?->can('sheq.hazard.triage'),
            'behavioursInstalled' => $companyId !== null
                && DB::table('sheq_behaviours')->where('company_id', $companyId)->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /*
         * Gated on reporting a hazard, not on managing the register.
         *
         * Anybody who can report a hazard can file an observation, and that is the point:
         * restricting it to safety staff would make the one register that depends on volume into
         * the one register only four people can write to.
         */
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'observation_type' => ['required', Rule::in(array_keys(ObservationService::TYPES))],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'activity' => ['nullable', 'string', 'max:60'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'contractor_id' => ['nullable', 'integer', Rule::exists('sheq_contractors', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'contractor_worker_id' => ['nullable', 'integer',
                Rule::exists('sheq_contractor_workers', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
            'hr_person_id' => ['nullable', 'integer', Rule::exists('hr_people', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId))],
            'behaviour_code' => ['nullable', 'string', 'max:60'],
            // The words are required and the code is not. An observer who cannot find the right
            // code must still be able to file what they saw — a dropdown that blocks the report
            // is a dropdown that loses the report.
            'behaviour' => ['required', 'string', 'max:2000'],
            'risk_rating' => ['nullable', Rule::in(ObservationService::RISK_RATINGS)],
            'immediate_action' => ['nullable', 'string', 'max:2000'],
            'work_stopped' => ['nullable', 'boolean'],
            'observed_at' => ['nullable', 'date'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        $created = $this->observations->record($companyId, $data, $request->user()?->id);

        return back()->with('success', sprintf('%s recorded.', $created['reference']));
    }

    /**
     * Raise a corrective action from an observation (OBS-3).
     *
     * Gated on hazard triage, which is the entitlement that already means "decide what a report
     * leads to". Anybody may file an observation; turning one into an obligation on a named
     * person with a due date is a different decision.
     */
    public function raiseAction(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.triage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.triage');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['LOW', 'MEDIUM', 'HIGH'])],
            'hierarchy_level' => ['nullable', Rule::in(['ELIMINATION', 'SUBSTITUTION',
                'ENGINEERING', 'ADMINISTRATIVE', 'PPE'])],
        ]);

        try {
            $action = $this->observations->raiseAction($companyId, $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('success', sprintf('%s raised.', $action['reference'] ?? 'Action'));
    }

    public function close(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->observations->close($this->requireCompany($request), $uuid,
            $data['note'] ?? null, $request->user()?->id);

        return back()->with('success', 'Observation closed.');
    }

    /** Put the starting behaviour vocabulary in front of a tenant that has none. */
    public function installBehaviours(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $added = $this->observations->installBehaviours($this->requireCompany($request),
            $request->user()?->id);

        return back()->with('success', $added === 0
            ? 'The behaviour list was already in place; nothing was changed.'
            : "Added {$added} behaviours. Edit them to match the work done here.");
    }
}
