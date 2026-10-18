<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\EnvironmentalService;
use App\Modules\Operations\Sheq\Services\WasteAndSpillService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Aspects, waste, spills and the numbers (ENV-1 to ENV-7).
 *
 * The screen leads with two counts that should both be zero: reportable spills nobody has
 * recorded a notification for, and hazardous consignments whose signed manifest has not come
 * back. Everything else here is a register; those two are open legal exposure, and they are the
 * reason this module needs an environmental section rather than an environmental report.
 */
class EnvironmentalRegisterController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(
        private readonly WasteAndSpillService $registers,
        private readonly EnvironmentalService $monitoring,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $days = max(30, min(730, (int) $request->integer('days', 180)));
        $from = Carbon::today()->subDays($days);
        $siteId = $this->permittedSite($request, $companyId);

        return Inertia::render('Modules/Sheq/Registers/EnvironmentalRegisters', [
            'noCompany' => $companyId === null,
            'days' => $days,
            'siteId' => $siteId,
            'aspects' => $companyId === null ? [] : $this->registers->aspects($companyId),
            'waste' => $companyId === null ? [] : $this->registers->waste($companyId,
                ['site_id' => $siteId, 'from' => $from->toDateString()]),
            'spills' => $companyId === null ? [] : $this->registers->spills($companyId,
                ['site_id' => $siteId, 'from' => $from]),
            'kpis' => $companyId === null ? null
                : $this->registers->kpis($companyId, $from, Carbon::now(), $siteId),
            'media' => collect(WasteAndSpillService::MEDIA)
                ->map(fn (string $l, string $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'conditions' => collect(WasteAndSpillService::CONDITIONS)
                ->map(fn (string $l, string $k) => ['key' => $k, 'label' => $l])->values()->all(),
            /*
             * Each receiving environment carries whether it makes a spill reportable, so the form
             * can say so as somebody picks it rather than after they have saved.
             */
            'receivingEnvironments' => collect(WasteAndSpillService::RECEIVING_ENVIRONMENTS)
                ->map(fn (array $m, string $k) => $m + ['key' => $k])->values()->all(),
            'disposalRoutes' => collect(WasteAndSpillService::DISPOSAL_ROUTES)
                ->map(fn (string $l, string $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'meters' => $companyId === null ? [] : DB::table('sheq_environmental_meters')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'uuid', 'code', 'name', 'aspect', 'unit', 'threshold_value',
                    'warning_value', 'threshold_source', 'capa_on_breach',
                    'investigate_on_breach'])
                ->map(fn ($m) => (array) $m)->all(),
            'legalLines' => $companyId === null ? [] : DB::table('sheq_legal_register_lines')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_applicable', true)
                ->orderBy('instrument')->orderBy('clause')->limit(500)
                ->get(['id', 'instrument', 'clause', 'requirement'])
                ->map(fn ($l) => (array) $l)->all(),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'contractors' => $companyId === null ? [] : DB::table('sheq_contractors')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($c) => (array) $c)->all(),
            'canRecord' => (bool) $request->user()?->can('sheq.hazard.report'),
            'canConfigure' => (bool) $request->user()?->can('sheq.configure'),
        ]);
    }

    // ── ENV-1 ─────────────────────────────────────────────────────────────────────────────────

    public function storeAspect(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'activity' => ['required', 'string', 'max:255'],
            'aspect' => ['required', 'string', 'max:255'],
            'impact' => ['required', 'string', 'max:255'],
            'medium' => ['nullable', Rule::in(array_keys(WasteAndSpillService::MEDIA))],
            'condition' => ['nullable', Rule::in(array_keys(WasteAndSpillService::CONDITIONS))],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'inherent_likelihood' => ['nullable', 'integer', 'min:1', 'max:6'],
            'inherent_severity' => ['nullable', 'integer', 'min:1', 'max:6'],
            'residual_likelihood' => ['nullable', 'integer', 'min:1', 'max:6'],
            'residual_severity' => ['nullable', 'integer', 'min:1', 'max:6'],
            'is_significant' => ['nullable', 'boolean'],
            'controls' => ['nullable', 'string', 'max:4000'],
            'legal_register_line_id' => ['nullable', 'integer',
                Rule::exists('sheq_legal_register_lines', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
            'meter_id' => ['nullable', 'integer', Rule::exists('sheq_environmental_meters', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'review_due' => ['nullable', 'date'],
        ]);

        $saved = $this->registers->saveAspect($companyId, $request->query('uuid'), $data,
            $request->user()?->id);

        return back()->with('success', sprintf('%s saved.', $saved['reference']));
    }

    // ── ENV-3 ─────────────────────────────────────────────────────────────────────────────────

    public function storeWaste(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'waste_stream' => ['required', 'string', 'max:120'],
            'is_hazardous' => ['nullable', 'boolean'],
            'classification_code' => ['nullable', 'string', 'max:40'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'contractor_id' => ['nullable', 'integer', Rule::exists('sheq_contractors', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'transporter' => ['nullable', 'string', 'max:255'],
            'transporter_permit' => ['nullable', 'string', 'max:120'],
            'vehicle_registration' => ['nullable', 'string', 'max:40'],
            'destination' => ['nullable', 'string', 'max:255'],
            'destination_permit' => ['nullable', 'string', 'max:120'],
            'manifest_number' => ['nullable', 'string', 'max:120'],
            'disposal_route' => ['nullable',
                Rule::in(array_keys(WasteAndSpillService::DISPOSAL_ROUTES))],
            'removed_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $saved = $this->registers->recordWaste($companyId, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            // The manifest refusal. Said as a message rather than a 500, because the moment to
            // insist is while the truck is still at the gate.
            return back()->withErrors(['manifest_number' => $e->getMessage()]);
        }

        return back()->with('success', sprintf('%s recorded.', $saved['reference']));
    }

    public function returnManifest(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $data = $request->validate(['returned_on' => ['nullable', 'date']]);

        $this->registers->returnManifest($this->requireCompany($request), $uuid,
            $data['returned_on'] ?? null, $request->user()?->id);

        return back()->with('success', 'Manifest recorded as returned.');
    }

    // ── ENV-4 ─────────────────────────────────────────────────────────────────────────────────

    public function storeSpill(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'substance' => ['required', 'string', 'max:255'],
            'is_hazardous' => ['nullable', 'boolean'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'location_text' => ['nullable', 'string', 'max:255'],
            'receiving_environment' => ['required',
                Rule::in(array_keys(WasteAndSpillService::RECEIVING_ENVIRONMENTS))],
            'occurred_at' => ['nullable', 'date'],
            'cause' => ['nullable', 'string', 'max:2000'],
            'containment' => ['nullable', 'string', 'max:2000'],
            'clean_up' => ['nullable', 'string', 'max:2000'],
            'contractor_id' => ['nullable', 'integer', Rule::exists('sheq_contractors', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'aspect_id' => ['nullable', 'integer',
                Rule::exists('sheq_environmental_aspects', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
        ]);

        $saved = $this->registers->recordSpill($companyId, $data, $request->user()?->id);

        return back()->with('success', $saved['notifiable']
            ? sprintf('%s recorded. This spill is reportable — record the notification to the '
                .'authority before closing it.', $saved['reference'])
            : sprintf('%s recorded.', $saved['reference']));
    }

    public function notifySpill(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $data = $request->validate([
            'authority_name' => ['required', 'string', 'max:255'],
            'authority_reference' => ['nullable', 'string', 'max:120'],
            'notified_at' => ['nullable', 'date'],
            'notification_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->registers->recordNotification($this->requireCompany($request), $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['authority_name' => $e->getMessage()]);
        }

        return back()->with('success', 'Notification recorded.');
    }

    public function updateSpill(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $data = $request->validate([
            'containment' => ['nullable', 'string', 'max:2000'],
            'clean_up' => ['nullable', 'string', 'max:2000'],
            'cause' => ['nullable', 'string', 'max:2000'],
            'contained' => ['nullable', 'boolean'],
            'cleaned_up' => ['nullable', 'boolean'],
            'close' => ['nullable', 'boolean'],
        ]);

        try {
            $this->registers->updateSpill($this->requireCompany($request), $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['spill' => $e->getMessage()]);
        }

        return back()->with('success', 'Updated.');
    }

    // ── ENV-5 ─────────────────────────────────────────────────────────────────────────────────

    /** Set a meter's limit and what a breach does. */
    public function updateMeterThreshold(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'threshold_value' => ['nullable', 'numeric', 'min:0'],
            'warning_value' => ['nullable', 'numeric', 'min:0'],
            'threshold_source' => ['nullable', 'string', 'max:255'],
            'capa_on_breach' => ['nullable', 'boolean'],
            'investigate_on_breach' => ['nullable', 'boolean'],
            'legal_register_line_id' => ['nullable', 'integer',
                Rule::exists('sheq_legal_register_lines', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
        ]);

        /*
         * A warning above the limit is a configuration mistake rather than a preference: it would
         * never fire, because anything past it has already breached.
         */
        if (isset($data['warning_value'], $data['threshold_value'])
            && (float) $data['warning_value'] >= (float) $data['threshold_value']) {
            return back()->withErrors(['warning_value' =>
                'The warning level has to be below the limit, or it can never fire — anything '
                .'past it has already breached.']);
        }

        $updated = DB::table('sheq_environmental_meters')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->update($data + ['updated_at' => now()]);

        if ($updated === 0) {
            return back()->withErrors(['meter' => 'No such meter in this company.']);
        }

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $request->user()?->id,
            'actor_type' => 'USER', 'action' => 'environmental.threshold_changed',
            'subject_type' => 'SheqEnvironmentalMeter', 'subject_id' => 0,
            'after' => json_encode($data + ['meter' => $uuid]),
            'reason' => 'The limit a single reading may not exceed, and what a breach does.',
        ]);

        return back()->with('success', 'Limit saved.');
    }

    /** Record a reading, which is where a breach is detected (ENV-5). */
    public function storeReading(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'meter_id' => ['required', 'integer',
                Rule::exists('sheq_environmental_meters', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
            'value' => ['required', 'numeric', 'min:0'],
            'reading_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->monitoring->record($companyId, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['value' => $e->getMessage()]);
        }

        if ($result['breached']) {
            return back()->with('success', sprintf(
                'Recorded, and it exceeded the limit of %s.%s',
                $result['threshold'],
                $result['action_reference']
                    ? ' '.$result['action_reference'].' was raised.' : ''));
        }

        return back()->with('success', $result['warning']
            ? 'Recorded. It is above the warning level but within the limit.'
            : 'Reading recorded.');
    }
}
