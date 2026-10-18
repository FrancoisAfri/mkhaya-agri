<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\EmergencyCommandService;
use App\Modules\Operations\Sheq\Services\EmergencyService;
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
 * The emergency command centre (EMR-1 to EMR-6).
 *
 * Read by somebody standing outside a building, on a phone, with people waiting. Two consequences
 * for this controller specifically:
 *
 * The board is one request. Everything the commander needs — the roll call, the responders, the
 * equipment, the hazard picture — arrives together, because a screen that loads the names and
 * then fetches the hazards is a screen that is half-drawn at the moment it is read.
 *
 * Marking somebody accounted for returns to the board rather than redirecting somewhere with a
 * message, so the count the commander is watching updates and nothing else moves.
 */
class EmergencyCommandController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(
        private readonly EmergencyCommandService $command,
        private readonly EmergencyService $emergency,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $siteId = $this->permittedSite($request, $companyId);

        // An emergency that is running is the only thing worth showing. The register is below it.
        $active = $companyId === null ? null : DB::table('sheq_emergencies')
            ->where('company_id', $companyId)->where('status', 'ACTIVE')->whereNull('deleted_at')
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('declared_at')->value('uuid');

        $open = $request->string('emergency')->toString() ?: $active;

        $board = null;

        if ($companyId !== null && $open !== null && $open !== '') {
            try {
                $board = $this->command->board($companyId, $open);
            } catch (\InvalidArgumentException) {
                // A stale uuid in the query string must not blank the whole screen.
                $board = null;
            }
        }

        return Inertia::render('Modules/Sheq/Registers/EmergencyCommand', [
            'noCompany' => $companyId === null,
            'siteId' => $siteId,
            'board' => $board,
            'register' => $companyId === null ? []
                : $this->command->register($companyId, $siteId),
            'drills' => $companyId === null ? []
                : $this->emergency->drillHistory($companyId, $siteId, 12),
            'overdueDrills' => $companyId === null ? []
                : $this->emergency->sitesOverdueForDrill($companyId),
            'substances' => $companyId === null ? [] : DB::table('sheq_hazardous_substances')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)
                ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
                ->orderBy('name')
                ->get(['uuid', 'site_id', 'name', 'un_number', 'hazard_class', 'quantity', 'unit',
                    'location_text', 'is_flammable', 'is_toxic', 'fire_fighting_media',
                    'incompatible_with'])
                ->map(fn ($r) => (array) $r)->all(),
            'resources' => $companyId === null ? []
                : $this->command->resources($companyId, $siteId),
            'types' => EmergencyService::TYPES,
            'statuses' => EmergencyCommandService::STATUSES,
            'categories' => EmergencyCommandService::CATEGORIES,
            'roles' => EmergencyCommandService::ROLES,
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            // `hr_people` has no `deleted_at`; the column that retires a person here is `status`.
            'people' => $companyId === null ? [] : DB::table('hr_people')
                ->where('company_id', $companyId)->where('status', 1)
                ->orderBy('first_name')->limit(500)
                ->get(['id', 'first_name', 'surname'])
                ->map(fn ($p) => ['id' => $p->id,
                    'name' => trim($p->first_name.' '.$p->surname)])->all(),
            'canDeclare' => (bool) $request->user()?->can('sheq.incident.report'),
            'canConfigure' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    /** EMR-1. */
    public function declare(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.incident.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.incident.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'site_id' => ['required', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'emergency_type' => ['required', Rule::in(EmergencyService::TYPES)],
            'incident_commander_id' => ['nullable', 'integer', Rule::exists('hr_people', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId))],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->command->declare($companyId, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            // The "already running" refusal names the emergency to join. It is the whole value of
            // the message, so it goes to the screen rather than becoming a generic failure.
            return back()->withErrors(['emergency_type' => $e->getMessage()]);
        }

        return redirect()
            ->route('sheq.emergency-command', ['emergency' => $result['uuid']])
            ->with('success', sprintf(
                '%s declared. %d people were on site and are on the roll call.',
                $result['reference'], $result['on_site'],
            ));
    }

    /** EMR-3. */
    public function mark(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.incident.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.incident.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'roll_call_uuid' => ['required', 'uuid'],
            'status' => ['required', Rule::in(EmergencyCommandService::STATUSES)],
            'location_text' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->command->mark($companyId, $uuid, $data['roll_call_uuid'],
                $data['status'], $request->user()?->id, $data);
        } catch (\InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success',
            sprintf('%s: %s.', $result['person_name'], strtolower($result['now'])));
    }

    /** EMR-2 — pull occupancy again without touching anything already decided. */
    public function refresh(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $emergency = DB::table('sheq_emergencies')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'site_id']);

        if ($emergency === null) {
            return back()->withErrors(['emergency' => 'No such emergency.']);
        }

        $total = $this->command->syncRollCall($companyId, (int) $emergency->id,
            (int) $emergency->site_id);

        return back()->with('success', "{$total} people on the roll call.");
    }

    public function standDown(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.incident.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.incident.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'stand_down_notes' => ['nullable', 'string', 'max:2000'],
            'stood_down_with_unaccounted_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->command->standDown($companyId, $uuid, $request->user()?->id, $data);
        } catch (\InvalidArgumentException|RuntimeException $e) {
            // The refusal says how many people are still outstanding. That number is the reason
            // somebody is about to type a justification, so it has to reach them.
            return back()->withErrors(['stood_down_with_unaccounted_reason' => $e->getMessage()]);
        }

        return back()->with('success', $result['unaccounted'] > 0
            ? sprintf('%s stood down with %d unaccounted for. A corrective action has been '
                .'raised.', $result['reference'], $result['unaccounted'])
            : $result['reference'].' stood down. Everybody was accounted for.');
    }

    /** EMR-4. */
    public function saveResource(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'kind' => ['required', Rule::in([EmergencyCommandService::EQUIPMENT,
                EmergencyCommandService::FACILITY])],
            'category' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'last_inspected_on' => ['nullable', 'date'],
            'next_inspection_due' => ['nullable', 'date'],
        ]);

        $this->command->saveResource($companyId, $data, $request->user()?->id);

        return back()->with('success', 'Recorded.');
    }

    /** EMR-5. */
    public function saveSubstance(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'site_id' => ['required', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'name' => ['required', 'string', 'max:255'],
            'un_number' => ['nullable', 'string', 'max:20'],
            'hazard_class' => ['nullable', 'string', 'max:40'],
            'ghs_classification' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'storage_conditions' => ['nullable', 'string', 'max:255'],
            'fire_fighting_media' => ['nullable', 'string', 'max:2000'],
            'incompatible_with' => ['nullable', 'string', 'max:2000'],
            'is_flammable' => ['nullable', 'boolean'],
            'is_toxic' => ['nullable', 'boolean'],
            'sds_document_id' => ['nullable', 'integer', Rule::exists('sheq_documents', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'sds_reviewed_on' => ['nullable', 'date'],
        ]);

        $this->command->saveSubstance($companyId, $data, $request->user()?->id);

        return back()->with('success', 'Recorded.');
    }

    /** EMR-6. */
    public function drillAttendance(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.incident.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.incident.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'people' => ['required', 'array', 'min:1'],
            'people.*.person_name' => ['required', 'string', 'max:255'],
            'people.*.subject_type' => ['nullable', 'string', 'max:20'],
            'people.*.contractor_name' => ['nullable', 'string', 'max:255'],
            'people.*.was_present' => ['nullable', 'boolean'],
            'people.*.accounted_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'people.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->command->recordDrillAttendance($companyId, $uuid, $data['people'],
                $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['people' => $e->getMessage()]);
        }

        return back()->with('success', sprintf('%d present, %d did not reach the assembly point.',
            $result['present'], $result['absent']));
    }

    /** EMR-4 — which competency makes somebody a first aider here. */
    public function setEmergencyRole(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'emergency_role' => ['nullable', Rule::in(EmergencyCommandService::ROLES)],
        ]);

        try {
            $this->command->setEmergencyRole($companyId, $uuid, $data['emergency_role'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['emergency_role' => $e->getMessage()]);
        }

        return back()->with('success', 'Saved.');
    }
}
