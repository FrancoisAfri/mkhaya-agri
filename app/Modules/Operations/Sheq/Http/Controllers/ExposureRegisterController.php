<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Services\ExposureRegisterService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * What people are exposed to (OH-1 to OH-6).
 *
 * Leads with the surveillance list, because that is the part with a legal obligation attached and
 * the part nobody can assemble by hand: somebody exposed above the action level for noise needs
 * audiometry whether or not anybody remembered to enrol them, and the list is derived from the
 * exposure rather than from an enrolment register.
 */
class ExposureRegisterController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(private readonly ExposureRegisterService $exposure) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $person = $request->integer('person') ?: null;
        $worker = $request->integer('worker') ?: null;

        return Inertia::render('Modules/Sheq/Registers/OccupationalHealth', [
            'noCompany' => $companyId === null,
            'agents' => $companyId === null ? [] : $this->exposure->agents($companyId),
            'groups' => $companyId === null ? [] : $this->exposure->groups($companyId),
            'results' => $companyId === null ? [] : $this->exposure->results($companyId, [
                'agent_id' => $request->integer('agent_id') ?: null,
                'site_id' => $this->permittedSite($request, $companyId),
                'exceeded' => $request->boolean('exceeded'),
            ]),
            /*
             * OH-4. Derived rather than enrolled: the obligation follows from what somebody was
             * exposed to, and an enrolment list is a list somebody has to remember to add to.
             */
            'surveillance' => $companyId === null ? []
                : $this->exposure->surveillanceDue($companyId),
            'history' => $companyId === null || ($person === null && $worker === null) ? null
                : $this->exposure->historyFor($companyId, $person, $worker),
            'historyFor' => ['person' => $person, 'worker' => $worker],
            'agentTypes' => collect(ExposureRegisterService::AGENT_TYPES)
                ->map(fn (string $l, string $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'sampleTypes' => collect(ExposureRegisterService::SAMPLE_TYPES)
                ->map(fn (string $l, string $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'ppeItems' => $companyId === null ? [] : DB::table('sheq_ppe_items')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'category'])->map(fn ($p) => (array) $p)->all(),
            'medicalTypes' => $companyId === null ? [] : DB::table('sheq_medical_types')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'examination_type'])->map(fn ($m) => (array) $m)->all(),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'people' => $companyId === null ? []
                : app(EmployeeDirectory::class)->roster($companyId),
            'workers' => $companyId === null ? [] : DB::table('sheq_contractor_workers as w')
                ->join('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
                ->where('w.company_id', $companyId)->whereNull('w.deleted_at')
                ->where('w.is_active', true)->orderBy('c.name')->orderBy('w.name')->limit(1000)
                ->get(['w.id', 'w.name', 'c.name as contractor_name'])
                ->map(fn ($w) => (array) $w)->all(),
            'agentsInstalled' => $companyId !== null
                && DB::table('sheq_exposure_agents')->where('company_id', $companyId)->exists(),
            'canRecord' => (bool) $request->user()?->can('sheq.hazard.report'),
            'canConfigure' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    public function installAgents(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $added = $this->exposure->installAgents($this->requireCompany($request),
            $request->user()?->id);

        return back()->with('success', $added === 0
            ? 'The register was already in place; nothing was changed.'
            : "Added {$added} agents with their published limits. Have an occupational hygienist "
                ."confirm them before relying on one as evidence.");
    }

    /** OH-2, which is also where OH-6 fires. */
    public function storeResult(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'agent_id' => ['required', 'integer', Rule::exists('sheq_exposure_agents', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'group_id' => ['nullable', 'integer', Rule::exists('sheq_exposure_groups', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'hr_person_id' => ['nullable', 'integer', Rule::exists('hr_people', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId))],
            'contractor_worker_id' => ['nullable', 'integer',
                Rule::exists('sheq_contractor_workers', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'location_text' => ['nullable', 'string', 'max:255'],
            'value' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:40'],
            'sample_type' => ['nullable',
                Rule::in(array_keys(ExposureRegisterService::SAMPLE_TYPES))],
            'duration_minutes' => ['nullable', 'numeric', 'min:0'],
            'measured_on' => ['nullable', 'date'],
            'measured_by' => ['nullable', 'string', 'max:255'],
            'laboratory' => ['nullable', 'string', 'max:255'],
            'certificate_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $saved = $this->exposure->recordResult($companyId, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['group_id' => $e->getMessage()]);
        }

        return back()->with('success', $saved['exceeded']
            ? sprintf('%s recorded, and it exceeded the limit. %d %s affected — check the '
                .'surveillance list.', $saved['reference'], $saved['affected'],
                $saved['affected'] === 1 ? 'person is' : 'people are')
            : sprintf('%s recorded.', $saved['reference']));
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'activity' => ['nullable', 'string', 'max:60'],
        ]);

        DB::table('sheq_exposure_groups')->insert($data + [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $companyId,
            'code' => strtoupper($data['code']),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Group added. Add the people in it next.');
    }

    public function addMember(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'hr_person_id' => ['nullable', 'integer'],
            'contractor_worker_id' => ['nullable', 'integer'],
            'from_date' => ['nullable', 'date'],
        ]);

        if (empty($data['hr_person_id']) && empty($data['contractor_worker_id'])) {
            return back()->withErrors(['hr_person_id' => 'Pick a person.']);
        }

        $groupId = DB::table('sheq_exposure_groups')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->value('id');

        if ($groupId === null) {
            return back()->withErrors(['group' => 'No such group in this company.']);
        }

        DB::table('sheq_exposure_group_members')->insert([
            'company_id' => $companyId,
            'group_id' => (int) $groupId,
            'hr_person_id' => $data['hr_person_id'] ?? null,
            'contractor_worker_id' => $data['contractor_worker_id'] ?? null,
            /*
             * Dated from today unless somebody says otherwise. Membership with no start date
             * attributes every historical result to them, including the ones measured before
             * they arrived.
             */
            'from_date' => $data['from_date'] ?? now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Added to the group.');
    }

    /** OH-5. */
    public function linkPpe(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'ppe_item_id' => ['required', 'integer', Rule::exists('sheq_ppe_items', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'is_required' => ['nullable', 'boolean'],
            'minimum_specification' => ['nullable', 'string', 'max:255'],
        ]);

        $agentId = DB::table('sheq_exposure_agents')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->value('id');

        if ($agentId === null) {
            return back()->withErrors(['agent' => 'No such agent in this company.']);
        }

        $this->exposure->linkPpe($companyId, (int) $agentId, (int) $data['ppe_item_id'],
            (bool) ($data['is_required'] ?? true), $data['minimum_specification'] ?? null,
            $request->user()?->id);

        return back()->with('success', 'Linked.');
    }

    /** Which examination an agent requires, and whether a breach raises anything (OH-4, OH-6). */
    public function updateAgent(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'limit_value' => ['nullable', 'numeric', 'min:0'],
            'limit_unit' => ['nullable', 'string', 'max:40'],
            'action_level' => ['nullable', 'numeric', 'min:0'],
            'limit_source' => ['nullable', 'string', 'max:255'],
            'requires_medical_surveillance' => ['nullable', 'boolean'],
            'medical_type_id' => ['nullable', 'integer', Rule::exists('sheq_medical_types', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'capa_on_exceedance' => ['nullable', 'boolean'],
            'investigate_on_exceedance' => ['nullable', 'boolean'],
        ]);

        /*
         * An action level above the limit is a configuration mistake: the action level exists to
         * catch exposure before it reaches the limit, so one above it can never fire first.
         */
        if (isset($data['action_level'], $data['limit_value'])
            && (float) $data['action_level'] >= (float) $data['limit_value']) {
            return back()->withErrors(['action_level' =>
                'The action level has to be below the limit — it exists to catch exposure before '
                .'it gets there.']);
        }

        $updated = DB::table('sheq_exposure_agents')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->update($data + ['updated_at' => now()]);

        if ($updated === 0) {
            return back()->withErrors(['agent' => 'No such agent in this company.']);
        }

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $request->user()?->id,
            'actor_type' => 'USER', 'action' => 'exposure.agent_changed',
            'subject_type' => 'SheqExposureAgent', 'subject_id' => 0,
            'after' => json_encode($data + ['agent' => $uuid]),
            'reason' => 'The limit, the surveillance it triggers and what an exceedance raises.',
        ]);

        return back()->with('success', 'Saved.');
    }
}
