<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\EmergencyService;
use App\Modules\Operations\Sheq\Services\EnvironmentalService;
use App\Modules\Operations\Sheq\Services\EquipmentService;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use App\Modules\Operations\Sheq\Services\PpeService;
use App\Modules\Operations\Sheq\Services\TrainingTrackingService;
use App\Modules\Operations\Sheq\Services\VisitorService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operational registers added for the full SHEQ scope: PPE, medical surveillance,
 * environmental monitoring, safety equipment, visitors, emergency preparedness and training
 * visibility.
 *
 * Separate from {@see RegistersController}, which serves the original registers (risk assessments,
 * permits, inspections, competency, documents, contractors, sites). Two controllers rather than one
 * very large one, split along the line where the screens were built — not an architectural
 * statement, just the smaller diff.
 *
 * One controller because they are the same screen shape — a register, an add form, and a list of
 * things falling due — and seven controllers repeating that would drift. Each action stays thin;
 * the rules live in the services, where the offline path and the API reach them too.
 */
class OperationalRegistersController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(
        private readonly PpeService $ppe,
        private readonly MedicalSurveillanceService $medical,
        private readonly EnvironmentalService $environmental,
        private readonly EquipmentService $equipment,
        private readonly VisitorService $visitors,
        private readonly EmergencyService $emergency,
        private readonly TrainingTrackingService $training,
    ) {}

    // ── PPE ───────────────────────────────────────────────────────────────────────────────────

    public function ppe(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Ppe', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'items' => $companyId === null ? [] : DB::table('sheq_ppe_items')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('name')->get(['uuid', 'id', 'code', 'name', 'category',
                    'lifespan_months', 'requires_size', 'is_active'])
                ->map(fn ($i) => (array) $i)->all(),
            'issues' => $companyId === null ? [] : $this->recentIssues($companyId),
            'due' => $companyId === null ? [] : $this->ppe->replacementsDue($companyId),
            'lowStock' => $companyId === null ? [] : $this->ppe->lowStock($companyId),
            'people' => $companyId === null ? [] : $this->people($companyId),
        ]);
    }

    public function issuePpe(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'ppe_item_id' => ['required', 'integer'],
            'hr_person_id' => ['required', 'integer'],
            'size' => ['nullable', 'string', 'max:20'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'signature' => ['nullable', 'string', 'max:500000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ppe->issue($companyId, $validated, $request->user()?->id);

        return back()->with('success', $result['replace_due']
            ? "Issued. Replacement due {$result['replace_due']}."
            : 'Issued.');
    }





    // ── Medical surveillance ──────────────────────────────────────────────────────────────────

    public function medical(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Medical', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            // Reading the clinical detail is a separate entitlement from scheduling around it.
            'canReadClinical' => (bool) $request->user()?->can('sheq.pii.read'),
            'types' => $companyId === null ? [] : DB::table('sheq_medical_types')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'uuid', 'code', 'name', 'examination_type',
                    'frequency_months', 'blocks_work_when_unfit'])
                ->map(fn ($t) => (array) $t)->all(),
            'due' => $companyId === null ? [] : $this->medical->dueWithin($companyId),
            'people' => $companyId === null ? [] : $this->people($companyId),
        ]);
    }

    public function storeMedical(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.competency.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.competency.manage');

        $validated = $request->validate([
            'medical_type_id' => ['required', 'integer'],
            'hr_person_id' => ['required', 'integer'],
            'examined_on' => ['required', 'date'],
            'expires_on' => ['nullable', 'date'],
            'fitness_outcome' => ['required', Rule::in(MedicalSurveillanceService::OUTCOMES)],
            'restrictions' => ['nullable', 'string', 'max:1000'],
            'clinical_findings' => ['nullable', 'string', 'max:5000'],
            'practitioner_name' => ['nullable', 'string', 'max:160'],
            'practitioner_number' => ['nullable', 'string', 'max:60'],
        ]);

        $this->medical->record($companyId, $validated, $request->user()?->id);

        return back()->with('success', 'Examination recorded.');
    }

    /**
     * One examination's clinical detail, for the people entitled to read it.
     *
     * `MedicalSurveillanceService::clinicalDetail()` decrypts the POPIA s26 fields — findings,
     * restrictions, the practitioner — and nothing called it. The register already computed
     * `canReadClinical` from `sheq.pii.read` and used it only to decide whether the *capture* form
     * showed a findings box: an occupational health practitioner could write a finding into the
     * system and had no way to read one back. Recording health information that cannot be
     * retrieved by the person responsible for it is worse than not recording it.
     *
     * Returned as JSON on demand rather than included in the register's props. Special personal
     * information should travel when somebody with the entitlement asks for one record, not sit in
     * the page payload of every examination on a list.
     */
    public function clinicalDetail(Request $request, string $uuid): JsonResponse
    {
        $companyId = $this->requireCompany($request);

        abort_unless($request->user()?->can('sheq.pii.read'), 403,
            'ENTITLEMENT_REQUIRED: sheq.pii.read');

        $detail = $this->medical->clinicalDetail($companyId, $uuid);

        /*
         * The read itself is recorded.
         *
         * POPIA treats health information as special personal information, and the one control
         * that makes "only the people who need it" meaningful is a record of who looked. The
         * screen says so to the person opening it; this is what makes that true rather than
         * reassuring. No clinical content in the log entry — an audit trail that copies the
         * finding into itself defeats the encryption it is auditing.
         */
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $request->user()?->id,
            'actor_type' => 'USER',
            'action' => 'medical.clinical_detail.read',
            'subject_type' => 'MedicalRecord',
            'subject_id' => (int) DB::table('sheq_medical_records')
                ->where('company_id', $companyId)->where('uuid', $uuid)->value('id'),
            'reason' => 'Clinical detail opened on the medical surveillance register.',
        ]);

        return response()->json($detail);
    }

    // ── Environmental ─────────────────────────────────────────────────────────────────────────

    public function environmental(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $from = $request->query('from', now()->startOfYear()->toDateString());
        $to = $request->query('to', now()->toDateString());

        return Inertia::render('Modules/Sheq/Registers/Environmental', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'period' => ['from' => $from, 'to' => $to],
            'meters' => $companyId === null ? [] : DB::table('sheq_environmental_meters')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('aspect')->orderBy('name')
                ->get(['id', 'uuid', 'code', 'name', 'aspect', 'unit', 'carbon_factor', 'is_active'])
                ->map(fn ($m) => (array) $m)->all(),
            'summary' => $companyId === null ? null
                : $this->environmental->summary($companyId, $from, $to),
            'trend' => $companyId === null ? [] : $this->environmental->trend($companyId),
            'stale' => $companyId === null ? [] : $this->environmental->staleMeters($companyId),
            'aspects' => EnvironmentalService::ASPECTS,
        ]);
    }

    public function storeReading(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'meter_id' => ['required', 'integer'],
            'reading_date' => ['required', 'date'],
            'value' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->environmental->record($companyId, $validated, $request->user()?->id);

        return back()->with('success', $result['co2e_kg'] === null
            ? 'Reading recorded. This meter has no emission factor, so it adds nothing to the carbon total.'
            : "Reading recorded — {$result['co2e_kg']} kg CO2e.");
    }

    // ── Equipment ─────────────────────────────────────────────────────────────────────────────

    public function equipment(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Equipment', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'types' => EquipmentService::TYPES,
            'equipment' => $companyId === null ? [] : DB::table('sheq_equipment as e')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'e.site_id')
                ->where('e.company_id', $companyId)->whereNull('e.deleted_at')
                ->orderBy('e.next_inspection_due')->limit(500)
                ->get(['e.uuid', 'e.asset_tag', 'e.name', 'e.equipment_type', 'e.location_text',
                    'e.status', 'e.next_inspection_due', 'e.qr_code', 'e.certificate_expires_on',
                    's.name as site_name'])
                ->map(fn ($e) => (array) $e + [
                    'overdue' => $e->next_inspection_due !== null
                        && Carbon::parse($e->next_inspection_due)->isPast(),
                ])->all(),
            'due' => $companyId === null ? [] : $this->equipment->inspectionsDue($companyId),
            'sites' => $companyId === null ? [] : $this->sites($companyId),
        ]);
    }

    public function storeEquipment(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'asset_tag' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'equipment_type' => ['required', Rule::in(EquipmentService::TYPES)],
            'site_id' => ['nullable', 'integer'],
            'location_text' => ['nullable', 'string', 'max:200'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'inspection_frequency_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'certificate_expires_on' => ['nullable', 'date'],
        ]);

        $result = $this->equipment->register($companyId, $validated, $request->user()?->id);

        return back()->with('success', "Registered with code {$result['qr_code']}.");
    }

    public function inspectEquipment(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'passed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->equipment->recordInspection($companyId, $uuid, $validated,
            $request->user()?->id);

        return back()->with($result['passed'] ? 'success' : 'warning', $result['passed']
            ? "Checked. Next due {$result['next_inspection_due']}."
            : 'Recorded as failed — this equipment is now out of service.');
    }

    // ── Visitors ──────────────────────────────────────────────────────────────────────────────

    public function visitors(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Visitors', [
            'noCompany' => $companyId === null,
            'onSite' => $companyId === null ? [] : $this->visitors->onSite($companyId),
            'overstaying' => $companyId === null ? [] : $this->visitors->overstaying($companyId),
            'expected' => $companyId === null ? [] : DB::table('sheq_visits')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereIn('status', ['REQUESTED', 'APPROVED', 'INDUCTED'])
                ->orderBy('expected_from')->limit(100)
                ->get(['uuid', 'reference', 'visitor_name', 'visitor_company', 'status',
                    'expected_from', 'badge_code'])
                ->map(fn ($v) => (array) $v)->all(),
            'sites' => $companyId === null ? [] : $this->sites($companyId),
            /*
             * Whether a visit can be requested at all.
             *
             * A visit is approval-driven: with no workflow there is nobody authorised to admit
             * anyone, and the request is refused. Asked here so the screen says so before a
             * visitor's details are typed, rather than after — the refusal used to arrive on
             * submit and took the form with it.
             */
            'approvalConfigured' => $companyId === null
                || app(\App\Modules\Operations\Sheq\Approvals\ApprovalService::class)
                    ->canBeRaised($companyId, 'VISITOR'),
            'approvalsHref' => '/sheq/approvals/workflows',
        ]);
    }

    public function requestVisit(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'visitor_name' => ['required', 'string', 'max:160'],
            'visitor_company' => ['nullable', 'string', 'max:160'],
            'visitor_contact' => ['nullable', 'string', 'max:60'],
            'vehicle_registration' => ['nullable', 'string', 'max:30'],
            'site_id' => ['nullable', 'integer'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'expected_from' => ['nullable', 'date'],
            'expected_to' => ['nullable', 'date', 'after_or_equal:expected_from'],
        ]);

        $this->visitors->request($companyId, $validated, (int) $request->user()->id);

        return back()->with('success', 'Visit requested. It needs approval before induction.');
    }

    public function visitorAction(Request $request, string $uuid, string $action): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        match ($action) {
            'induct' => $this->visitors->recordInduction($companyId, $uuid, $request->validate([
                'signature' => ['required', 'string', 'max:500000'],
                'nda_accepted' => ['nullable', 'boolean'],
                'ppe_issued' => ['nullable', 'array'],
            ])),
            'sign-in' => $this->visitors->signIn($companyId, $uuid),
            'sign-out' => $this->visitors->signOut($companyId, $uuid),
            default => abort(404),
        };

        return back()->with('success', match ($action) {
            'induct' => 'Induction recorded. The visitor may now sign in.',
            'sign-in' => 'Signed in.',
            default => 'Signed out.',
        });
    }

    // ── Emergency ─────────────────────────────────────────────────────────────────────────────

    public function emergency(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Emergency', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'types' => EmergencyService::TYPES,
            'plans' => $companyId === null ? [] : DB::table('sheq_emergency_plans as p')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'p.site_id')
                ->where('p.company_id', $companyId)->whereNull('p.deleted_at')
                ->orderBy('p.emergency_type')
                ->get(['p.uuid', 'p.name', 'p.emergency_type', 'p.assembly_points',
                    'p.review_due', 'p.is_active', 's.name as site_name'])
                ->map(fn ($p) => array_merge((array) $p, [
                    'assembly_points' => json_decode((string) $p->assembly_points, true) ?: [],
                ]))->all(),
            /*
             * Which plan actually applies, per emergency type, at the site asked about.
             *
             * `EmergencyService::planFor()` resolves the precedence — a site plan beats the
             * company-wide one — and nothing called it, so the register listed every plan and left
             * the reader to work out which governed. That is a reasonable thing to ask of somebody
             * doing an annual review and a bad thing to ask of somebody with a fire: two "FIRE"
             * rows, one site-specific, and no statement of which is in force.
             */
            'inForce' => $companyId === null ? [] : $this->plansInForce($companyId, $request),
            'siteId' => $this->permittedSite($request, $companyId),
            'drills' => $companyId === null ? [] : $this->emergency->drillHistory($companyId),
            'overdue' => $companyId === null ? [] : $this->emergency->sitesOverdueForDrill($companyId),
            'sites' => $companyId === null ? [] : $this->sites($companyId),
        ]);
    }

    /**
     * The plan in force for each emergency type, with the gap said as a gap.
     *
     * A type with no plan at all is the finding: it is the emergency nobody has written a procedure
     * for, and it is invisible in a list of the plans that do exist.
     *
     * @return list<array<string,mixed>>
     */
    private function plansInForce(int $companyId, Request $request): array
    {
        $siteId = $this->permittedSite($request, $companyId);

        return array_map(function (string $type) use ($companyId, $siteId) {
            $plan = $this->emergency->planFor($companyId, $type, $siteId);

            return [
                'emergency_type' => $type,
                'plan' => $plan,
                'review_overdue' => $plan !== null && ! empty($plan['review_due'])
                    && Carbon::parse($plan['review_due'])->isPast(),
            ];
        }, EmergencyService::TYPES);
    }

    public function storeDrill(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'drill_date' => ['required', 'date'],
            'drill_type' => ['required', 'string', 'max:30'],
            'evacuation_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'participants' => ['required', 'integer', 'min:0'],
            'unaccounted' => ['required', 'integer', 'min:0'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'improvements' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->emergency->recordDrill($companyId, $validated, $request->user()?->id);

        return back()->with($result['action_raised'] ? 'warning' : 'success',
            $result['action_raised']
                ? 'Drill recorded. People were unaccounted for, so a corrective action has been raised.'
                : 'Drill recorded — everybody accounted for.');
    }

    public function storeEmergencyPlan(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'emergency_type' => ['required', Rule::in(EmergencyService::TYPES)],
            'site_id' => ['nullable', 'integer'],
            'procedure' => ['nullable', 'string', 'max:10000'],
            'assembly_points' => ['required', 'array', 'min:1'],
            'assembly_points.*.name' => ['required', 'string', 'max:160'],
            'emergency_contacts' => ['nullable', 'array'],
        ]);

        $this->emergency->createPlan($companyId, $validated, $request->user()?->id);

        return back()->with('success', 'Emergency plan published.');
    }

    // ── Training (read-only view of LMS) ──────────────────────────────────────────────────────

    public function training(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Training', [
            'noCompany' => $companyId === null,
            // Says so on the screen, so nobody looks for an "add course" button that should not
            // exist here.
            'lmsAvailable' => $this->training->isAvailable(),
            'compliance' => $companyId === null ? null : $this->training->compliance($companyId),
            'expiring' => $companyId === null ? [] : $this->training->expiringWithin($companyId),
        ]);
    }

    // ── Shared lookups ────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function people(int $companyId): array
    {
        return DB::table('hr_people')->where('company_id', $companyId)
            ->orderBy('first_name')->limit(2000)
            ->get(['id', 'first_name', 'surname'])
            ->map(fn ($p) => ['id' => (int) $p->id,
                'name' => trim($p->first_name.' '.$p->surname)])->all();
    }

    /** @return list<array<string,mixed>> */
    private function sites(int $companyId): array
    {
        return DB::table('sheq_sites')->where('company_id', $companyId)
            ->where('is_active', true)->orderBy('name')
            ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all();
    }

    /** @return list<array<string,mixed>> */
    private function recentIssues(int $companyId): array
    {
        return DB::table('sheq_ppe_issues as i')
            ->join('sheq_ppe_items as it', 'it.id', '=', 'i.ppe_item_id')
            ->leftJoin('hr_people as p', 'p.id', '=', 'i.hr_person_id')
            ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
            ->orderByDesc('i.issued_on')->limit(100)
            ->get(['i.uuid', 'i.size', 'i.quantity', 'i.issued_on', 'i.replace_due', 'i.status',
                'it.name', 'p.first_name', 'p.surname'])
            ->map(fn ($i) => [
                'uuid' => $i->uuid, 'item' => $i->name, 'size' => $i->size,
                'quantity' => (int) $i->quantity, 'issued_on' => $i->issued_on,
                'replace_due' => $i->replace_due, 'status' => $i->status,
                'person' => trim(($i->first_name ?? '').' '.($i->surname ?? '')) ?: '—',
            ])->all();
    }
}
