<?php

namespace App\Modules\Operations\Sheq\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use App\Modules\Operations\Sheq\Http\Api\ProblemDetails;
use App\Modules\Operations\Sheq\Services\EquipmentService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use App\Modules\Operations\Sheq\Services\PpeService;
use App\Modules\Operations\Sheq\Services\TrainingTrackingService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The endpoints a field app calls (§6.2).
 *
 * Written for the app rather than for the web UI, which is a different job. Three things shape it:
 *
 * **One call per screen.** A phone at a gate on a bad connection cannot make six requests to render
 * a page. `me()` returns everything the home screen needs — who you are, what you must do, what has
 * lapsed — in one round trip, even though that means a slightly fatter payload.
 *
 * **Everything is offline-first.** The bulk sync endpoints already exist at `/sync/pull` and
 * `/sync/push`; these complement them for the things a device needs immediately and cannot queue —
 * a gate decision, an induction to complete, a piece of equipment scanned in front of you.
 *
 * **Errors are RFC 9457**, like the rest of the SHEQ API, so the app has one error parser.
 */
class MobileApiController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly AccessDecisionService $access,
        private readonly InductionService $inductions,
        private readonly MedicalSurveillanceService $medical,
        private readonly TrainingTrackingService $training,
        private readonly PpeService $ppe,
        private readonly EquipmentService $equipment,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * `GET /api/v1/sheq/mobile/me`
     *
     * The home screen in one call: identity, compliance standing, what is waiting on you, and what
     * is about to lapse.
     */
    public function me(Request $request): JsonResponse
    {
        $companyId = $this->companyId();
        $user = $request->user();

        $person = DB::table('hr_people')->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first(['id', 'first_name', 'surname', 'employee_number']);

        $personId = $person?->id;

        $fitness = $personId === null ? ['cleared' => true, 'reasons' => []]
            : $this->medical->isClearedForWork($companyId, (int) $personId);

        $trained = $this->training->isTrainedForWork($companyId, (int) $user->id);

        return response()->json([
            'data' => [
                'user_id' => (int) $user->id,
                'hr_person_id' => $personId === null ? null : (int) $personId,
                'name' => $person === null ? $user->email
                    : trim($person->first_name.' '.$person->surname),
                'employee_number' => $person->employee_number ?? null,
                'company_id' => $companyId,

                // The single question the app's home screen exists to answer.
                'may_work' => $fitness['cleared'] && $trained['cleared'],
                'blockers' => $fitness['reasons'],
                'warnings' => $trained['reasons'],

                'approvals_waiting' => count($this->approvals->awaiting($companyId, (int) $user->id, 50)),
                'ppe_held' => $personId === null ? []
                    : $this->ppe->heldBy($companyId, (int) $personId),
                'permissions' => $this->permissionsFor($user),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * `GET /api/v1/sheq/mobile/gate?identifier=8501015800083&site_id=3`
     *
     * A gate decision for a scanned identifier. Used by a guard's phone where no turnstile
     * integration exists — the same decision the hardware path makes, so a site can enforce
     * compliance with a phone and a queue rather than waiting for a capital project.
     */
    public function gate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:80'],
            'site_id' => ['nullable', 'integer'],
            'direction' => ['nullable', 'string', 'max:20'],
            // The work being checked for, so a supervisor at a boom can ask "may they work at
            // height" rather than only "may they come in" (CON-15).
            'activity' => ['nullable', 'string', 'max:60'],
            'enforce' => ['nullable', 'boolean'],
        ]);

        if (! $request->user()?->can('sheq.report.view')) {
            return ProblemDetails::make(403, 'entitlement-required',
                'You do not have this permission',
                'Making a gate decision requires the `sheq.report.view` permission.',
                ['required_permission' => 'sheq.report.view']);
        }

        $decision = $this->access->decideAndRecord($this->companyId(), $validated['identifier'], [
            'site_id' => $validated['site_id'] ?? null,
            'direction' => $validated['direction'] ?? 'ENTRY',
            'activity' => isset($validated['activity'])
                ? strtoupper(trim($validated['activity'])) : null,
            'enforce' => (bool) ($validated['enforce'] ?? true),
            'device_ref' => $request->header('X-Device-Id'),
        ]);

        return response()->json(['data' => $decision]);
    }

    /**
     * `GET /api/v1/sheq/mobile/induction?audience=CONTRACTOR&site_id=3`
     *
     * The induction content to present. Answers are stripped server-side.
     */
    public function induction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audience' => ['nullable', 'string', 'max:30'],
            'site_id' => ['nullable', 'integer'],
        ]);

        $induction = $this->inductions->present($this->companyId(),
            $validated['audience'] ?? 'CONTRACTOR', $validated['site_id'] ?? null);

        if ($induction === null) {
            return ProblemDetails::make(404, 'no-induction', 'No induction is published',
                'This company has not published an induction for that audience yet.');
        }

        return response()->json(['data' => $induction]);
    }

    /** `POST /api/v1/sheq/mobile/induction/{uuid}/complete` */
    public function completeInduction(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'person_name' => ['required', 'string', 'max:160'],
            'person_identifier' => ['nullable', 'string', 'max:80'],
            'subject_type' => ['required', 'string', 'max:30'],
            'subject_id' => ['nullable', 'integer'],
            'answers' => ['nullable', 'array'],
            'nda_accepted' => ['nullable', 'boolean'],
            'signature' => ['nullable', 'string', 'max:2000000'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $result = $this->inductions->complete($this->companyId(), $uuid,
            $validated + ['via' => 'MOBILE']);

        return response()->json(['data' => $result], $result['passed'] ? 201 : 200);
    }

    /**
     * `GET /api/v1/sheq/mobile/scan/{code}`
     *
     * What a scanned QR code is. The field path for equipment checks: point the phone at the
     * extinguisher, get its history and whether it is due.
     */
    public function scan(Request $request, string $code): JsonResponse
    {
        $equipment = $this->equipment->findByCode($this->companyId(), $code);

        if ($equipment !== null) {
            return response()->json(['data' => ['type' => 'EQUIPMENT'] + $equipment]);
        }

        // A visitor badge scans the same way, so a guard does not need to know which kind of code
        // is on the card in front of them.
        $visit = DB::table('sheq_visits')->where('company_id', $this->companyId())
            ->where('badge_code', $code)->whereNull('deleted_at')
            ->first(['uuid', 'reference', 'visitor_name', 'visitor_company', 'status']);

        if ($visit !== null) {
            return response()->json(['data' => ['type' => 'VISITOR'] + (array) $visit]);
        }

        // An inspection subject: the guard or technician scans the fire panel and gets its
        // checklist, which is the whole reason the codes exist.
        $schedule = DB::table('sheq_inspection_schedules as sc')
            ->join('sheq_inspection_templates as t', 't.id', '=', 'sc.template_id')
            ->where('sc.company_id', $this->companyId())->where('sc.qr_code', $code)
            ->first(['sc.uuid', 'sc.next_due', 't.code', 't.title', 't.schema']);

        if ($schedule !== null) {
            return response()->json(['data' => [
                'type' => 'INSPECTION',
                'uuid' => $schedule->uuid,
                'code' => $schedule->code,
                'title' => $schedule->title,
                'next_due' => $schedule->next_due,
                'sections' => json_decode((string) $schedule->schema, true)['sections'] ?? [],
            ]]);
        }

        // PPE stock. Scanning the shelf label is how a storeman issues without typing anything,
        // and it is the path most likely to be used one-handed while holding a pair of boots.
        $stock = DB::table('sheq_ppe_stock as s')
            ->join('sheq_ppe_items as i', 'i.id', '=', 's.ppe_item_id')
            ->where('s.company_id', $this->companyId())->where('s.barcode', $code)
            ->whereNull('i.deleted_at')->where('i.is_active', true)
            ->first(['i.id as ppe_item_id', 'i.code', 'i.name', 'i.requires_size',
                'i.lifespan_months', 's.size', 's.quantity_on_hand', 's.reorder_level']);

        if ($stock !== null) {
            return response()->json(['data' => [
                'type' => 'PPE',
                'ppe_item_id' => (int) $stock->ppe_item_id,
                'code' => $stock->code,
                'name' => $stock->name,
                'size' => $stock->size,
                'requires_size' => (bool) $stock->requires_size,
                'quantity_on_hand' => (int) $stock->quantity_on_hand,
                // Surfaced so the storeman knows before issuing the last pair, rather than
                // discovering it when the register refuses them.
                'low_stock' => $stock->quantity_on_hand <= $stock->reorder_level,
                'out_of_stock' => $stock->quantity_on_hand <= 0,
                'lifespan_months' => $stock->lifespan_months === null
                    ? null : (int) $stock->lifespan_months,
            ]]);
        }

        return ProblemDetails::make(404, 'unknown-code', 'Nothing matches this code',
            'The code is not registered equipment, PPE stock, an inspection subject, or a live '
            .'visitor badge in your company.');
    }

    /** `POST /api/v1/sheq/mobile/equipment/{uuid}/inspect` */
    public function inspectEquipment(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'passed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->equipment->recordInspection(
            $this->companyId(), $uuid, $validated, $request->user()?->id)]);
    }

    /**
     * `POST /api/v1/sheq/mobile/ppe/issue`
     *
     * Issue from a scan. The field path: scan the shelf label, pick the person, sign, done — with
     * the same stock locking and replacement-date arithmetic the web form uses, because the rules
     * live in the service rather than in either screen.
     */
    public function issuePpe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ppe_item_id' => ['required', 'integer'],
            'hr_person_id' => ['required', 'integer'],
            'size' => ['nullable', 'string', 'max:20'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'signature' => ['nullable', 'string', 'max:2000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->ppe->issue(
            $this->companyId(), $validated, $request->user()?->id)], 201);
    }

    /** `GET /api/v1/sheq/mobile/approvals` — what is waiting on this user. */
    public function approvals(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->approvals->awaiting(
            $this->companyId(), (int) $request->user()->id)]);
    }

    /** `POST /api/v1/sheq/mobile/approvals/{uuid}/decide` */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVED,REJECTED'],
            'note' => ['nullable', 'string', 'max:2000'],
            'signature' => ['nullable', 'string', 'max:2000000'],
        ]);

        return response()->json(['data' => $this->approvals->decide($this->companyId(), $uuid,
            (int) $request->user()->id, $validated)]);
    }

    /**
     * `GET /api/v1/sheq/mobile/bootstrap`
     *
     * Reference data an offline device needs cached: sites, hazard categories, permit types,
     * inspection templates. Fetched once on sign-in and refreshed when the version changes, so the
     * app can raise a hazard with no signal at all.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $companyId = $this->companyId();

        return response()->json([
            'data' => [
                'sites' => DB::table('sheq_sites')->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->get(['id', 'uuid', 'name', 'code', 'site_type', 'timezone'])
                    ->map(fn ($s) => (array) $s)->all(),
                'hazard_categories' => ['MECHANICAL', 'ELECTRICAL', 'CHEMICAL', 'STRUCTURAL',
                    'ERGONOMIC', 'BIOLOGICAL', 'ENVIRONMENTAL', 'BEHAVIOURAL', 'HOUSEKEEPING'],
                'incident_types' => ['INJURY', 'NEAR_MISS', 'PROPERTY_DAMAGE', 'ENVIRONMENTAL',
                    'VEHICLE', 'SECURITY', 'FIRE', 'DANGEROUS_OCCURRENCE'],
                'permit_types' => DB::table('sheq_permit_requirements')
                    ->where('company_id', $companyId)->distinct()
                    ->pluck('permit_type')->all(),
                'inspection_templates' => DB::table('sheq_inspection_templates')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
                    ->whereNull('deleted_at')
                    ->get(['uuid', 'code', 'title', 'schema', 'scoring_mode'])
                    ->map(fn ($t) => array_merge((array) $t, [
                        'schema' => json_decode((string) $t->schema, true),
                    ]))->all(),
                // Bumped when any of the above changes, so the device knows to refetch rather
                // than pulling this payload on every launch.
                'version' => md5((string) DB::table('sheq_sites')->where('company_id', $companyId)
                    ->max('updated_at').DB::table('sheq_inspection_templates')->max('updated_at')),
            ],
        ]);
    }

    /** @return list<string> */
    private function permissionsFor($user): array
    {
        return DB::table('permissions')->where('name', 'like', 'sheq.%')
            ->pluck('name')
            ->filter(fn ($permission) => $user->can($permission))
            ->values()->all();
    }

    private function companyId(): int
    {
        return (int) $this->current->id();
    }
}
