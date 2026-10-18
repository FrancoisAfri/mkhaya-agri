<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\CommitteeService;
use App\Modules\Procurement\Governance\Services\ConflictOfInterestService;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Evaluation committees (§1.2) and the administrator's side of conflict of interest (§1.3).
 */
class CommitteeController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly CommitteeService $committees,
        private readonly ConflictOfInterestService $coi,
        private readonly OptionLists $options,
        private readonly Capabilities $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $committees = $companyId === null ? collect() : DB::table('procgov_committees as c')
            ->leftJoin('users as ch', 'ch.id', '=', 'c.chairperson_id')
            ->leftJoin('hr_people as chp', 'chp.user_id', '=', 'ch.id')
            ->where('c.company_id', $companyId)
            ->whereNull('c.deleted_at')
            ->orderBy('c.name')
            ->select([
                'c.id', 'c.uuid', 'c.reference', 'c.name', 'c.committee_type', 'c.status',
                'c.appointment_date', 'c.start_date', 'c.end_date', 'c.quorum',
                'c.requires_external_member',
                DB::raw("trim(coalesce(chp.first_name,'') || ' ' || coalesce(chp.surname,'')) as chairperson"),
            ])
            ->selectSub(
                DB::table('procgov_committee_members as m')
                    ->selectRaw('count(*)')
                    ->whereColumn('m.committee_id', 'c.id')
                    ->whereNull('m.deleted_at')
                    ->where('m.status', 'ACTIVE'),
                'member_count'
            )
            ->get();

        return Inertia::render('Modules/ProcurementGovernance/Committees/Index', [
            'committees' => $committees,
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'committeeTypes' => $companyId ? $this->options->committeeTypes($companyId) : [],
            'roles' => CommitteeService::ROLES,
            'noCompany' => $companyId === null,
            'can' => ['manage' => (bool) $request->user()?->can('procgov.committee.manage')],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'committee_type' => ['nullable', 'string', 'max:60'],
            'terms_of_reference' => ['nullable', 'string', 'max:10000'],
            'chairperson_id' => ['nullable', 'integer', 'exists:users,id'],
            'secretary_id' => ['nullable', 'integer', 'exists:users,id', 'different:chairperson_id'],
            'appointment_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'quorum' => ['nullable', 'integer', 'min:1', 'max:50'],
            'requires_external_member' => ['boolean'],
        ]);

        $committee = $this->committees->create($companyId, $data);

        return redirect()
            ->route('procgov.committees.show', $committee->uuid)
            ->with('success', __('procurement_governance.flash.committees.created', ['reference' => $committee->reference]));
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);
        $committee = $this->find($companyId, $uuid);

        return Inertia::render('Modules/ProcurementGovernance/Committees/Show', [
            'committee' => $committee,
            'members' => $companyId ? $this->committees->members($companyId, (int) $committee->id) : [],
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'roles' => CommitteeService::ROLES,
            'reviews' => DB::table('procgov_tender_reviews')
                ->where('committee_id', $committee->id)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(['uuid', 'reference', 'title', 'status', 'evaluation_deadline']),
            'can' => ['manage' => (bool) $request->user()?->can('procgov.committee.manage')],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function addMember(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $committee = $this->find($companyId, $uuid);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_external' => ['boolean'],
            'external_name' => ['nullable', 'string', 'max:190'],
            'external_email' => ['nullable', 'email', 'max:190'],
            'external_organisation' => ['nullable', 'string', 'max:190'],
            'member_role' => ['required', Rule::in(CommitteeService::ROLES)],
            'is_voting' => ['boolean'],
            'appointed_at' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            $this->committees->addMember($companyId, (int) $committee->id, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.committees.member_added'));
    }

    public function endMembership(Request $request, string $uuid, int $memberId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->find($companyId, $uuid);

        $data = $request->validate([
            'status' => ['required', Rule::in(['SUSPENDED', 'RESIGNED', 'ENDED'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->committees->endMembership($companyId, $memberId, $data['status'], $data['reason'] ?? null);

        return back()->with('success', __('procurement_governance.flash.committees.membership_ended'));
    }

    public function activate(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $committee = $this->find($companyId, $uuid);

        $problems = $this->committees->activate($companyId, (int) $committee->id);

        if ($problems !== []) {
            return back()->with('error', __('procurement_governance.flash.committees.not_constituted', ['problems' => implode(' ', $problems)]));
        }

        return back()->with('success', __('procurement_governance.flash.committees.activated', ['reference' => $committee->reference]));
    }

    /** §1.3 — the administrator resolving a declared conflict. */
    public function resolveConflict(Request $request, string $declarationUuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $declaration = DB::table('procgov_coi_declarations')
            ->where('company_id', $companyId)
            ->where('uuid', $declarationUuid)
            ->first();

        abort_if(! $declaration, 404);

        $data = $request->validate([
            'resolution' => ['required', Rule::in(['CLEARED', 'RECUSED', 'REMOVED', 'ESCALATED'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->coi->resolve($companyId, (int) $declaration->id, $data['resolution'], $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.committees.conflict_resolved', ['resolution' => $data['resolution']]));
    }

    private function find(?int $companyId, string $uuid): object
    {
        $committee = DB::table('procgov_committees')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $committee, 404);

        return $committee;
    }
}
