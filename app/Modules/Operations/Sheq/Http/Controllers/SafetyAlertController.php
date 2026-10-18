<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\SafetyAlertService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Safety alerts: carrying a finding to the people exposed to the same hazard.
 *
 * The list leads with drafts rather than with what has already gone out, because a drafted alert
 * that was never issued is the failure this screen exists to prevent — the finding reached a form
 * and stopped there.
 */
class SafetyAlertController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly SafetyAlertService $alerts) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $alerts = $companyId === null ? collect() : DB::table('sheq_safety_alerts as a')
            ->leftJoin('sheq_incidents as i', function ($join) {
                $join->on('i.id', '=', 'a.source_id')->where('a.source_type', '=', 'INCIDENT');
            })
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            // Drafts first: one sitting unissued is the whole failure mode.
            ->orderByRaw("case a.status when 'DRAFT' then 0 else 1 end")
            ->orderByDesc('a.created_at')
            ->limit(200)
            ->get([
                'a.uuid', 'a.reference', 'a.title', 'a.severity', 'a.status', 'a.issued_at',
                'a.what_happened', 'a.why_it_happened', 'a.what_we_changed',
                'a.acknowledgement_required', 'i.reference as incident_reference', 'i.uuid as incident_uuid',
                DB::raw('(select count(*) from sheq_safety_alert_reads r where r.alert_id = a.id) as reads'),
            ])
            ->map(fn ($a) => array_merge((array) $a, ['reads' => (int) $a->reads]));

        return Inertia::render('Modules/Sheq/SafetyAlerts/Index', [
            'alerts' => $alerts->values(),
            'summary' => [
                'draft' => $alerts->where('status', 'DRAFT')->count(),
                'issued' => $alerts->where('status', 'ISSUED')->count(),
            ],
            // Incidents whose findings are approved and which have no alert yet: the queue of
            // learnings nobody has passed on.
            'awaiting' => $companyId === null ? [] : $this->awaitingAnAlert($companyId),
            'can' => [
                'manage' => (bool) $request->user()?->can('sheq.safety_alert.manage'),
            ],
            'noCompany' => $companyId === null,
            'edition' => config('edition.edition'),
        ]);
    }

    /** Approved findings that nobody has turned into an alert. */
    private function awaitingAnAlert(int $companyId): array
    {
        return DB::table('sheq_incidents as i')
            ->join('sheq_investigations as v', function ($join) use ($companyId) {
                $join->on('v.incident_id', '=', 'i.id')
                    ->where('v.company_id', '=', $companyId)
                    ->where('v.status', '=', 'APPROVED');
            })
            ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sheq_safety_alerts as a')
                ->whereColumn('a.source_id', 'i.id')
                ->where('a.source_type', 'INCIDENT')
                ->where('a.company_id', $companyId)
                ->whereNull('a.deleted_at'))
            ->orderByDesc('v.approved_at')
            ->limit(50)
            ->get(['i.uuid', 'i.reference', 'i.description', 'i.potential_severity', 'v.approved_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function draft(Request $request, string $incidentUuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.safety_alert.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.safety_alert.manage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $this->alerts->draftFromIncident($companyId, $incidentUuid, $request->user()?->id);

        return back()->with('success', 'Alert drafted from the approved findings.');
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.safety_alert.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.safety_alert.manage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'what_happened' => ['nullable', 'string', 'max:5000'],
            'why_it_happened' => ['nullable', 'string', 'max:5000'],
            'what_we_changed' => ['nullable', 'string', 'max:5000'],
            'severity' => ['nullable', 'string', 'max:20'],
            'acknowledgement_required' => ['nullable', 'boolean'],
        ]);

        $this->alerts->update($companyId, $uuid, $validated, $request->user()?->id);

        return back()->with('success', 'Alert updated.');
    }

    public function issue(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.safety_alert.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.safety_alert.manage');

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return back()->with('error', $this->noCompanyMessage($request));
        }

        $this->alerts->issue($companyId, $uuid, $request->user()?->id);

        return back()->with('success', 'Alert issued.');
    }

    /** Anyone who can see an alert can mark that they have read it. */
    public function read(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->companyId($request);
        $userId = $request->user()?->id;

        if ($companyId === null || $userId === null) {
            return back();
        }

        $this->alerts->markRead($companyId, $uuid, $userId);

        return back()->with('success', 'Marked as read.');
    }
}
