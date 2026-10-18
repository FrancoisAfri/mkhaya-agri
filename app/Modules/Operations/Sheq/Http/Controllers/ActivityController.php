<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ActivityService;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue of work done on site, and what each kind takes (CON-15).
 *
 * Its own screen rather than a panel on settings, because it is a register: a safety manager
 * maintains it, a gate reads it, and an auditor asks to see it. Burying "what does working at
 * height require" three cards down a settings page is how it ends up unmaintained, and an
 * unmaintained catalogue is a gate that refuses nobody.
 */
class ActivityController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ActivityService $activities) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Activities', [
            'noCompany' => $companyId === null,
            'rows' => $companyId === null ? [] : $this->activities->all($companyId),
            /*
             * Credential codes somebody has actually filed, plus the ones the shipped catalogue
             * names. A free-text box here is how WORK_AT_HEIGHT and WORKING_AT_HEIGHT end up as
             * two requirements neither of which anybody satisfies.
             */
            'credentialCodes' => $companyId === null
                ? [] : $this->activities->knownCredentialCodes($companyId),
            /*
             * Whether a missing ticket actually refuses anybody, said on the screen that sets
             * the requirements. A catalogue that refuses nothing is a catalogue somebody thinks
             * is protecting them.
             */
            'enforced' => $companyId === null ? false
                : (bool) (app(ContractorComplianceStatus::class)->rulesFor($companyId)
                    ['MISSING_ACTIVITY_COMPETENCY'] ?? false),
            'rulesHref' => '/sheq/contractors',
            /**
             * How many contractor workers could do each activity today.
             *
             * The number that tells a safety manager whether a requirement is real or theoretical:
             * an activity nobody on the estate qualifies for either needs certificates chased or
             * needs its requirement corrected, and until it is shown nobody finds out which.
             */
            'readiness' => $companyId === null ? [] : $this->readiness($companyId),
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'shippedCount' => count(ActivityService::SHIPPED),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $this->activities->save($this->requireCompany($request), null,
            $this->validated($request), $request->user()?->id);

        return back()->with('success', 'Activity added. A gate asking about it now knows what it '
            .'takes.');
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $this->activities->save($this->requireCompany($request), $uuid,
            $this->validated($request), $request->user()?->id);

        return back()->with('success', 'Activity updated.');
    }

    /** Retire one. Soft-deleted, so permits and access events recorded against it stay readable. */
    public function destroy(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $this->activities->retire($this->requireCompany($request), $uuid, $request->user()?->id);

        return back()->with('success', 'Retired. Work already recorded against it is unchanged.');
    }

    /**
     * Put the shipped South African catalogue in front of a tenant who has none.
     *
     * The empty-register problem: a safety manager opening a blank list has to invent twelve
     * activities and remember what each one takes before the gate does anything at all.
     */
    public function install(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $added = $this->activities->install($this->requireCompany($request), $request->user()?->id);

        return back()->with('success', $added === 0
            ? 'Nothing added — every shipped activity is already in your catalogue.'
            : "{$added} activities added. Check what each one requires before you rely on it.");
    }

    /**
     * Per activity: how many active contractor workers hold everything it requires, out of how
     * many there are.
     *
     * The number that says whether a requirement is real or theoretical. An activity nobody on
     * the estate qualifies for either needs certificates chased or needs its requirement
     * corrected — and until somebody is shown the figure, nobody finds out which. It is the
     * difference between a catalogue that protects a site and one that only looks like it does.
     *
     * Computed from the credentials already in memory rather than per worker per activity: a
     * tenant with 400 contractor workers and 12 activities would otherwise be 4,800 queries to
     * draw one page.
     *
     * @return array<string, array{ready:int, total:int}>
     */
    private function readiness(int $companyId): array
    {
        $workerIds = DB::table('sheq_contractor_workers as w')
            ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
            ->where('w.is_active', true)
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->pluck('w.id');

        $total = $workerIds->count();

        if ($total === 0) {
            return [];
        }

        $today = Carbon::today();

        // Every live, accepted, in-date credential, grouped by worker. One query.
        $held = DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)->whereIn('worker_id', $workerIds)
            ->whereNull('deleted_at')
            ->where('status', 'ACCEPTED')
            ->where(fn ($q) => $q->whereNull('expires_on')
                ->orWhere('expires_on', '>=', $today->toDateString()))
            ->get(['worker_id', 'code', 'activities'])
            ->groupBy('worker_id');

        $out = [];

        foreach ($this->activities->all($companyId, true) as $activity) {
            $required = $activity['required_credentials'];

            if ($required === []) {
                $out[$activity['code']] = ['ready' => $total, 'total' => $total];

                continue;
            }

            $ready = 0;

            foreach ($workerIds as $workerId) {
                $theirs = $held[$workerId] ?? collect();

                $satisfies = collect($required)->every(function (string $code) use ($theirs, $activity) {
                    return $theirs->contains(function ($credential) use ($code, $activity) {
                        if (strtoupper((string) $credential->code) !== $code) {
                            return false;
                        }

                        // A credential restricted to other activities is not proof of this one.
                        $scope = json_decode((string) ($credential->activities ?? ''), true);

                        return ! is_array($scope) || $scope === []
                            || in_array($activity['code'],
                                array_map(fn ($a) => strtoupper((string) $a), $scope), true);
                    });
                });

                $ready += $satisfies ? 1 : 0;
            }

            $out[$activity['code']] = ['ready' => $ready, 'total' => $total];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            // Upper case, no spaces: this is what a reader, a permit and an API send.
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'required_credentials' => ['present', 'array'],
            'required_credentials.*' => ['string', 'max:60', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'is_high_risk' => ['boolean'],
            'requires_permit' => ['boolean'],
            /*
             * The five other evidence kinds CON-26 lists. Each absent from the payload reads as
             * false in the service, which is right for a form that posts only its ticked boxes.
             */
            'requires_medical' => ['boolean'],
            'requires_induction' => ['boolean'],
            'requires_risk_assessment' => ['boolean'],
            'requires_method_statement' => ['boolean'],
            'requires_equipment_certification' => ['boolean'],
            /*
             * PPE codes, the same shape as the credential list. Looser than credentials on
             * purpose: there is no PPE register to pick from, and the gate only ever prints
             * this list for whoever is standing at it.
             */
            'required_ppe' => ['nullable', 'array'],
            'required_ppe.*' => ['string', 'max:60'],
            'is_active' => ['boolean'],
        ]);
    }
}
