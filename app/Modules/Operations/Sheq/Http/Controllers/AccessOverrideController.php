<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\AccessOverrideService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The register of who has been let past a gate they should not have been (CON-17).
 *
 * A page of its own, because the override is the one control in the module that works by being
 * visible. Granting it is easy on purpose — a contractor with work to do and no way through is
 * how people end up signed in under a colleague's name — and what makes it safe is that every
 * one is listed, with who approved it, why, until when, and on what conditions.
 *
 * Buried on a contractor's profile, an override is only ever seen by somebody who already went
 * looking for that contractor. Here it is seen by whoever opens the page, which is the point.
 */
class AccessOverrideController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly AccessOverrideService $overrides) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Overrides', [
            'noCompany' => $companyId === null,
            'rows' => $companyId === null ? [] : $this->overrides->all($companyId),
            'endingSoon' => $companyId === null ? []
                : $this->overrides->endingWithin($companyId, 48),
            'contractors' => $companyId === null ? [] : $this->contractors($companyId),
            'workers' => $companyId === null ? [] : $this->workers($companyId),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => ['value' => (int) $s->id, 'title' => $s->name])->all(),
            'activities' => $companyId === null ? [] : DB::table('sheq_activities')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn ($a) => ['value' => $a->code, 'title' => $a->name])->all(),
            /*
             * Who may be named as the approver. CON-17 asks for one as a separate fact from
             * whoever typed it, because the person at the keyboard is not always the person
             * authorising — and an audit of "who let them in" has to name the latter.
             */
            'approvers' => $companyId === null ? [] : $this->approvers($companyId),
            'maxDays' => AccessOverrideService::MAX_DAYS,
            'canManage' => (bool) $request->user()?->can('sheq.contractor.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'subject_type' => ['required', Rule::in(AccessOverrideService::SUBJECTS)],
            'subject_uuid' => ['required', 'uuid'],
            // Long enough to be reviewable. The service checks it again, because this is the
            // one field somebody reading a refused-then-admitted entry has to work from.
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'site_id' => ['nullable', 'integer',
                Rule::exists('sheq_sites', 'id')->where('company_id', $companyId),
            ],
            'activity' => ['nullable', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['required', 'date', 'after:now'],
            'approved_by' => ['nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
        ]);

        $this->overrides->grant($companyId, $data, $request->user()?->id);

        return back()->with('success',
            'Override granted. It is on the register and it expires by itself.');
    }

    public function revoke(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->overrides->revoke($this->requireCompany($request), $uuid, $data['reason'],
            $request->user()?->id);

        return back()->with('success', 'Withdrawn. That gate is back where it was.');
    }

    /** @return list<array<string,mixed>> */
    private function contractors(int $companyId): array
    {
        return DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->limit(1000)
            ->get(['uuid', 'name', 'access_status'])
            ->map(fn ($c) => [
                'value' => $c->uuid,
                'title' => $c->name,
                // The status is on the picker because the commonest override is for a blocked
                // contractor, and scrolling a list of two hundred names to find the one that
                // needs it is how the wrong one gets picked.
                'subtitle' => $c->access_status,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function workers(int $companyId): array
    {
        return DB::table('sheq_contractor_workers as w')
            ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
            ->where('w.is_active', true)
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->orderBy('c.name')->orderBy('w.name')->limit(2000)
            ->get(['w.uuid', 'w.name', 'w.trade', 'c.name as contractor'])
            ->map(fn ($w) => [
                'value' => $w->uuid,
                'title' => $w->name,
                'subtitle' => trim($w->contractor.($w->trade ? ' · '.$w->trade : '')),
            ])->all();
    }

    /**
     * People who may be named as having authorised it.
     *
     * Anybody in the tenant with the contractor permission — not an arbitrary name field,
     * because an approver who is not an account is an approver nobody can ask about it.
     *
     * @return list<array<string,mixed>>
     */
    private function approvers(int $companyId): array
    {
        return DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.company_id', $companyId)
            ->when(DB::getSchemaBuilder()->hasColumn('users', 'deleted_at'),
                fn ($q) => $q->whereNull('u.deleted_at'))
            ->where('u.status', 1)
            ->orderBy('p.first_name')->limit(500)
            ->get(['u.id', 'u.email',
                DB::raw("nullif(trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')), '') as name")])
            ->map(fn ($u) => [
                'value' => (int) $u->id,
                // Named by email where there is no HR record, because an account without one is
                // still a person somebody can ask — and a blank entry in the list is not.
                'title' => $u->name ?? $u->email,
            ])->all();
    }
}
