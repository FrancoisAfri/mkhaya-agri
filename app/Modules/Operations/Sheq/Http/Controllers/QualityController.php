<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Services\QualityAnalytics;
use App\Modules\Operations\Sheq\Services\QualityService;
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
 * The Q in SHEQ (QLT-1 to QLT-8).
 *
 * Non-conformances, rework and the figures behind them, on one screen with the requirements they
 * are measured against. They are read together: an NCR means nothing without the requirement it
 * breached, and a repeat-defect count means nothing without the inspection count it came out of.
 *
 * The refusal in `verify` is the one that matters. QLT-7 stops the person who did the corrective
 * work from signing it off, and it has to explain itself — somebody blocked and told only "not
 * permitted" assumes the system is broken and asks an administrator to do it instead, which
 * produces exactly the record the rule exists to prevent.
 */
class QualityController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(
        private readonly QualityService $quality,
        private readonly QualityAnalytics $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $days = max(30, min(730, (int) $request->integer('days', 90)));
        $from = Carbon::today()->subDays($days);
        $siteId = $this->permittedSite($request, $companyId);

        $selected = $request->query('ncr');

        $detail = null;

        if ($companyId !== null && $selected !== null) {
            try {
                $ncr = $this->quality->findNcr($companyId, $selected);

                $detail = [
                    'ncr' => (array) $ncr,
                    'investigation' => $this->quality->investigation($companyId, $selected),
                    /*
                     * The blocker for THIS reader, not in the abstract. Whether somebody may
                     * verify depends on who they are, so the screen has to ask on their behalf
                     * rather than render a button and discover the answer on submit.
                     */
                    'verification_blocker' => $this->quality
                        ->verificationBlocker($ncr, $request->user()?->id),
                ];
            } catch (RuntimeException) {
                $detail = null;
            }
        }

        return Inertia::render('Modules/Sheq/Registers/Quality', [
            'noCompany' => $companyId === null,
            'days' => $days,
            'siteId' => $siteId,
            'ncrs' => $companyId === null ? [] : $this->quality->ncrs($companyId, [
                'status' => $request->query('status'),
                'severity' => $request->query('severity'),
                'site_id' => $siteId,
            ]),
            'detail' => $detail,
            'rework' => $companyId === null ? []
                : $this->quality->rework($companyId, ['site_id' => $siteId]),
            'requirements' => $companyId === null ? []
                : $this->quality->requirements($companyId),
            'analytics' => $companyId === null ? null
                : $this->analytics->summary($companyId, $from, Carbon::now(), $siteId),
            'requirementTypes' => collect(QualityService::REQUIREMENT_TYPES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()->all(),
            'disciplines' => collect(QualityService::DISCIPLINES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()->all(),
            'severities' => QualityService::SEVERITIES,
            'highSeverities' => QualityService::HIGH_SEVERITIES,
            'reworkReasons' => collect(QualityService::REWORK_REASONS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()->all(),
            /*
             * The defect vocabulary, by discipline. The thing the repeat-defect figure depends
             * on: a defect typed in prose is unfindable, and the same defect against a code is
             * countable.
             */
            'defects' => QualityService::SHIPPED_DEFECTS,
            'templates' => $companyId === null ? [] : DB::table('sheq_inspection_templates')
                ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
                ->whereNull('deleted_at')->where('status', 'PUBLISHED')
                ->whereNotNull('discipline')
                ->orderBy('discipline')->orderBy('title')
                ->get(['uuid', 'code', 'title', 'discipline', 'version', 'pass_threshold'])
                ->map(fn ($t) => (array) $t)->all(),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'contractors' => $companyId === null ? [] : DB::table('sheq_contractors')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($c) => (array) $c)->all(),
            'people' => $companyId === null ? []
                : app(EmployeeDirectory::class)->roster($companyId),
            'canRaise' => (bool) $request->user()?->can('sheq.hazard.report'),
            'canVerify' => (bool) $request->user()?->can('sheq.action.verify'),
            'canConfigure' => (bool) $request->user()?->can('sheq.configure'),
            'userId' => $request->user()?->id,
        ]);
    }

    // ── QLT-1 ─────────────────────────────────────────────────────────────────────────────────

    public function storeRequirement(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'requirement_type' => ['required',
                Rule::in(array_keys(QualityService::REQUIREMENT_TYPES))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'source' => ['nullable', 'string', 'max:255'],
            'acceptance_criteria' => ['nullable', 'string', 'max:2000'],
            'target_value' => ['nullable', 'numeric'],
            'target_unit' => ['nullable', 'string', 'max:40'],
            'discipline' => ['nullable', Rule::in(array_keys(QualityService::DISCIPLINES))],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'owner_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $saved = $this->quality->saveRequirement($companyId, $request->query('uuid'), $data,
            $request->user()?->id);

        return back()->with('success', sprintf('%s saved.', $saved['reference']));
    }

    // ── QLT-3 ─────────────────────────────────────────────────────────────────────────────────

    public function storeNcr(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'quality_requirement_id' => ['nullable', 'integer',
                Rule::exists('sheq_quality_requirements', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
            'requirement_text' => ['nullable', 'string', 'max:500'],
            'defect_code' => ['nullable', 'string', 'max:60'],
            'defect' => ['required', 'string', 'max:2000'],
            'discipline' => ['nullable', Rule::in(array_keys(QualityService::DISCIPLINES))],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'location_text' => ['nullable', 'string', 'max:255'],
            'contractor_id' => ['nullable', 'integer', Rule::exists('sheq_contractors', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'responsible_id' => ['nullable', 'integer'],
            'severity' => ['required', Rule::in(QualityService::SEVERITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $created = $this->quality->raiseNcr($companyId, $data, $request->user()?->id);

        return redirect('/sheq/quality?ncr='.$created['uuid'])
            ->with('success', sprintf('%s raised.', $created['reference']));
    }

    /** QLT-6. */
    public function raiseAction(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.triage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.triage');

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'hierarchy_level' => ['nullable', Rule::in(['ELIMINATION', 'SUBSTITUTION',
                'ENGINEERING', 'ADMINISTRATIVE', 'PPE'])],
        ]);

        try {
            $action = $this->quality->raiseAction($this->requireCompany($request), $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['ncr' => $e->getMessage()]);
        }

        return back()->with('success', sprintf('%s raised.', $action['reference'] ?? 'Action'));
    }

    /** QLT-5. */
    public function investigate(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.triage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.triage');

        $data = $request->validate([
            'method' => ['nullable', Rule::in(['FIVE_WHY', 'FISHBONE', 'ICAM'])],
        ]);

        try {
            $this->quality->investigate($this->requireCompany($request), $uuid,
                $data['method'] ?? null, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['ncr' => $e->getMessage()]);
        }

        return back()->with('success', 'Root-cause investigation opened.');
    }

    public function recordCorrection(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $data = $request->validate([
            'corrected_by' => ['nullable', 'integer'],
            'correction_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->quality->recordCorrection($this->requireCompany($request), $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['ncr' => $e->getMessage()]);
        }

        return back()->with('success', 'Recorded. It now needs verifying.');
    }

    /** QLT-7. */
    public function verify(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.action.verify'), 403,
            'ENTITLEMENT_REQUIRED: sheq.action.verify');

        $data = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->quality->verify($this->requireCompany($request), $uuid, $data,
                $request->user()?->id);
        } catch (RuntimeException $e) {
            // The sentence, not a 403. Somebody blocked from closing their own work and told
            // only "not permitted" asks an administrator to do it instead.
            return back()->withErrors(['verify' => $e->getMessage()]);
        }

        return back()->with('success', 'Verified and closed.');
    }

    public function voidNcr(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $this->quality->voidNcr($this->requireCompany($request), $uuid, $data['reason'],
            $request->user()?->id);

        return back()->with('success', 'Voided.');
    }

    // ── QLT-4 ─────────────────────────────────────────────────────────────────────────────────

    public function storeRework(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.hazard.report'), 403,
            'ENTITLEMENT_REQUIRED: sheq.hazard.report');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'ncr_uuid' => ['nullable', 'uuid'],
            'description' => ['required', 'string', 'max:500'],
            'reason_code' => ['nullable', Rule::in(array_keys(QualityService::REWORK_REASONS))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'contractor_id' => ['nullable', 'integer', Rule::exists('sheq_contractors', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        $this->quality->recordRework($companyId, $data, $request->user()?->id);

        return back()->with('success', 'Rework recorded.');
    }

    public function verifyRework(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.action.verify'), 403,
            'ENTITLEMENT_REQUIRED: sheq.action.verify');

        $data = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->quality->verifyRework($this->requireCompany($request), $uuid,
            $data['verification_notes'] ?? null, $request->user()?->id);

        return back()->with('success', 'Rework verified.');
    }
}
