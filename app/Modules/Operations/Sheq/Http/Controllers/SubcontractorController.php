<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Compliance\SubcontractorService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Who appointed whom, and what each principal may see about it (CON-25).
 *
 * The register was flat. Every firm answered directly to the host, so a principal contractor —
 * the party Construction Regulation 7 makes responsible for the subcontractors it appoints — had
 * no way to see whether the firms beneath it were in order, and the host had no way to show them
 * except by email, which is out of date before it arrives.
 *
 * The visibility control on this page is the part worth being careful about. Being accountable
 * for a firm's compliance does not make its staff's medical records yours to read, and it
 * certainly does not entitle you to a competitor's affairs. So the host chooses, per principal,
 * and the summary is the only thing that cannot be switched off — a principal shown an empty
 * screen reasonably concludes there is nothing wrong.
 */
class SubcontractorController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly SubcontractorService $subcontractors) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $contractors = $companyId === null ? [] : DB::table('sheq_contractors as c')
            ->leftJoin('sheq_contractors as p', 'p.id', '=', 'c.parent_contractor_id')
            ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
            ->orderBy('c.name')
            ->get(['c.id', 'c.uuid', 'c.name', 'c.trade', 'c.access_status', 'c.is_active',
                'c.parent_contractor_id', 'p.name as principal_name', 'p.uuid as principal_uuid'])
            ->map(fn ($c) => (array) $c)->all();

        $selected = $this->selected($request, $contractors);

        return Inertia::render('Modules/Sheq/Registers/Subcontractors', [
            'noCompany' => $companyId === null,
            'contractors' => array_map(fn (array $c) => [
                'uuid' => $c['uuid'], 'name' => $c['name'], 'trade' => $c['trade'],
                'access_status' => $c['access_status'], 'is_active' => (bool) $c['is_active'],
                'principal_name' => $c['principal_name'],
                'principal_uuid' => $c['principal_uuid'],
            ], $contractors),
            'selected' => $selected === null ? null
                : ['uuid' => $selected['uuid'], 'name' => $selected['name']],
            /*
             * The rollup exactly as the principal would be shown it, through the same guard the
             * portal uses. A host configuring what a contractor may see has to be able to see
             * what that produces — the alternative is deciding blind and finding out from the
             * contractor.
             */
            'rollup' => $selected === null || $companyId === null ? null
                : $this->subcontractors->visibleTo($companyId, (int) $selected['id']),
            'visibilityOptions' => collect(SubcontractorService::VISIBILITY)
                ->map(fn (string $note, string $key) => [
                    'key' => $key, 'note' => $note,
                    'required' => $key === 'COMPLIANCE_SUMMARY',
                ])->values()->all(),
            'visibility' => $selected === null || $companyId === null
                ? SubcontractorService::DEFAULT_VISIBILITY
                : $this->subcontractors->visibilityFor($companyId, (int) $selected['id']),
            'canManage' => (bool) $request->user()?->can('sheq.contractor.manage'),
        ]);
    }

    public function appoint(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'principal' => ['required', 'uuid'],
            'subcontractor' => ['required', 'uuid', 'different:principal'],
        ]);

        try {
            $this->subcontractors->appoint($companyId, $data['principal'],
                $data['subcontractor'], $request->user()?->id);
        } catch (RuntimeException $e) {
            // The message, not a 500. Somebody arranging a contractor tree has made an
            // understandable mistake and needs to be told which one.
            return back()->withErrors(['subcontractor' => $e->getMessage()]);
        }

        return back()->with('success', 'Appointment recorded.');
    }

    public function release(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $this->subcontractors->release($this->requireCompany($request), $uuid,
            $request->user()?->id);

        return back()->with('success',
            'Released. They now answer directly to you rather than to a principal contractor.');
    }

    public function updateVisibility(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $data = $request->validate([
            'visibility' => ['present', 'array'],
            'visibility.*' => ['string'],
        ]);

        $saved = $this->subcontractors->setVisibility($this->requireCompany($request), $uuid,
            $data['visibility'], $request->user()?->id);

        return back()->with('success', sprintf(
            'Saved. This principal contractor will be shown %d of %d available items.',
            count($saved), count(SubcontractorService::VISIBILITY)));
    }

    /** @param  list<array<string,mixed>>  $contractors */
    private function selected(Request $request, array $contractors): ?array
    {
        $uuid = $request->query('contractor');

        foreach ($contractors as $contractor) {
            if ($contractor['uuid'] === $uuid) {
                return $contractor;
            }
        }

        /*
         * Defaults to a firm that actually has subcontractors, so the page opens on something
         * worth looking at rather than on whichever contractor happens to sort first.
         */
        $principals = array_column($contractors, 'parent_contractor_id');

        foreach ($contractors as $contractor) {
            if (in_array($contractor['id'], $principals)) {
                return $contractor;
            }
        }

        return $contractors[0] ?? null;
    }
}
