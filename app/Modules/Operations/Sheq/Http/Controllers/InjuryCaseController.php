<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\InjuryCaseService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Injury cases: who is off work, who is on light duty, and who is back.
 *
 * The one question an injury management system has to answer is who is still away, and it is the
 * question the module could not answer at all — the day counts were two typed numbers on an
 * incident and nothing followed the person behind them.
 *
 * Diagnosis and restrictions are special personal information, so the list carries only what a
 * supervisor needs to plan work: the dates and the day counts. The clinical detail stays on the
 * case, behind the permission that already governs it.
 */
class InjuryCaseController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly InjuryCaseService $cases) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $cases = $companyId === null ? collect() : DB::table('sheq_injury_cases as c')
            ->join('sheq_incidents as i', 'i.id', '=', 'c.incident_id')
            ->leftJoin('hr_people as p', 'p.id', '=', 'c.hr_person_id')
            ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
            // Still away first, longest away at the top: that is the order somebody works in.
            ->orderByRaw("case c.status when 'OPEN' then 0 when 'RETURNED' then 1 else 2 end")
            ->orderBy('c.injured_on')
            ->limit(300)
            ->get([
                'c.uuid', 'c.reference', 'c.status', 'c.injured_on', 'c.expected_return_on',
                'c.returned_on', 'c.days_lost', 'c.days_restricted', 'c.coida_claim_number',
                'i.reference as incident_reference', 'i.uuid as incident_uuid', 'i.description',
                DB::raw("nullif(trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')), '') as person"),
            ])
            ->map(fn ($c) => array_merge((array) $c, [
                'days_lost' => (int) $c->days_lost,
                'days_restricted' => (int) $c->days_restricted,
            ]));

        return Inertia::render('Modules/Sheq/InjuryCases/Index', [
            'cases' => $cases->values(),
            'summary' => [
                'away' => $cases->where('status', 'OPEN')->count(),
                'returned' => $cases->where('status', 'RETURNED')->count(),
                'days_lost' => $cases->sum('days_lost'),
                'no_claim' => $cases->whereNull('coida_claim_number')->count(),
            ],
            // Injuries with nobody being followed: the gap this screen is meant to surface.
            'untracked' => $companyId === null ? [] : $this->injuriesWithoutACase($companyId),
            'can' => ['manage' => (bool) $request->user()?->can('sheq.injury_case.manage')],
            'noCompany' => $companyId === null,
            'edition' => config('edition.edition'),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/InjuryCases/Show', [
            'injuryCase' => $companyId === null ? null : $this->cases->view($companyId, $uuid),
            'can' => ['manage' => (bool) $request->user()?->can('sheq.injury_case.manage')],
            'noCompany' => $companyId === null,
        ]);
    }

    /** Incidents that hurt somebody and have no case following them. */
    private function injuriesWithoutACase(int $companyId): array
    {
        return DB::table('sheq_incidents as i')
            ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
            ->whereIn('i.treatment_level', ['MEDICAL_TREATMENT', 'RESTRICTED_WORK', 'LOST_TIME'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sheq_injury_cases as c')
                ->whereColumn('c.incident_id', 'i.id')->whereNull('c.deleted_at'))
            ->orderByDesc('i.occurred_at')
            ->limit(50)
            ->get(['i.uuid', 'i.reference', 'i.description', 'i.occurred_at', 'i.treatment_level'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function store(Request $request, string $incidentUuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.injury_case.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.injury_case.manage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $validated = $request->validate([
            'injured_on' => ['nullable', 'date'],
            'expected_return_on' => ['nullable', 'date'],
            'hr_person_id' => ['nullable', 'integer'],
            'incident_person_id' => ['nullable', 'integer'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'restrictions' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->cases->open($companyId, $incidentUuid, $validated, $request->user()?->id);

        return back()->with('success', 'Case opened.');
    }

    public function record(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.injury_case.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.injury_case.manage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $validated = $request->validate([
            'event_type' => ['required', 'string',
                'in:BOOKED_OFF,RESTRICTED_DUTY,RETURNED_FULL,MEDICAL_VISIT,REVIEW'],
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'practitioner' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->cases->record($companyId, $uuid, $validated, $request->user()?->id);

        return back()->with('success', 'Recorded.');
    }

    public function close(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.injury_case.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.injury_case.manage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $this->cases->close($companyId, $uuid, $request->user()?->id);

        return back()->with('success', 'Case closed.');
    }

    public function claim(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.injury_case.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.injury_case.manage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $validated = $request->validate([
            'coida_claim_number' => ['required', 'string', 'max:60'],
        ]);

        $this->cases->recordCoidaClaim($companyId, $uuid, $validated['coida_claim_number']);

        return back()->with('success', 'Claim number recorded.');
    }
}
