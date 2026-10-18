<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\RiskIntelligenceService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Whether the risk picture is getting worse, and what dangerous work is live (RISK-1 to RISK-5).
 *
 * The high-risk board opens first on purpose. Everything else on this screen is a trend read once
 * a month by somebody in an office; the board is read now, by somebody who has to decide whether
 * to let work continue — and the information on it is otherwise spread across the permit register,
 * the roster, the assessments and the gate log.
 */
class RiskIntelligenceController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(private readonly RiskIntelligenceService $risk) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $months = max(1, min(24, (int) $request->integer('months', 6)));
        $from = Carbon::today()->subMonths($months);
        $siteId = $this->permittedSite($request, $companyId);

        return Inertia::render('Modules/Sheq/Registers/RiskIntelligence', [
            'noCompany' => $companyId === null,
            'months' => $months,
            'siteId' => $siteId,
            'board' => $companyId === null ? null
                : $this->risk->highRiskBoard($companyId, $siteId),
            'deterioration' => $companyId === null ? null
                : $this->risk->deterioration($companyId, $from, $siteId),
            'trends' => $companyId === null ? null : $this->risk->trends($companyId, $siteId),
            'ineffective' => $companyId === null ? null
                : $this->risk->ineffectiveControls($companyId, $from),
            'verificationSources' => RiskIntelligenceService::VERIFICATION_SOURCES,
            'results' => RiskIntelligenceService::RESULTS,
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'canVerify' => (bool) $request->user()?->can('sheq.risk_assessment.author'),
        ]);
    }

    /** Record that a control was checked (RISK-3). */
    public function verifyControl(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.risk_assessment.author'), 403,
            'ENTITLEMENT_REQUIRED: sheq.risk_assessment.author');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'result' => ['required', Rule::in(RiskIntelligenceService::RESULTS)],
            'source_type' => ['nullable',
                Rule::in(RiskIntelligenceService::VERIFICATION_SOURCES)],
            'source_reference' => ['nullable', 'string', 'max:80'],
            'verified_on' => ['nullable', 'date'],
            'site_id' => ['nullable', 'integer', Rule::exists('sheq_sites', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $controlId = DB::table('sheq_controls')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->value('id');

        if ($controlId === null) {
            return back()->withErrors(['control' => 'No such control in this company.']);
        }

        $this->risk->verifyControl($companyId, (int) $controlId, $data['result'], $data,
            $request->user()?->id);

        return back()->with('success', $data['result'] === 'FAILED'
            ? 'Recorded as failed. A control that fails repeatedly stops being a barrier — the '
                .'assessment that counts it needs revisiting.'
            : 'Recorded.');
    }

    /**
     * Link a control to what enforces it (RISK-4).
     *
     * The links a control already had — a document, a competency, an inspection template — plus
     * the two the requirement adds. A control with none of them is a sentence in a document
     * nobody is trained on, that no inspection checks and no permit requires.
     */
    public function linkControl(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.risk_assessment.author'), 403,
            'ENTITLEMENT_REQUIRED: sheq.risk_assessment.author');

        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'linked_document_id' => ['nullable', 'integer', Rule::exists('sheq_documents', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'linked_competency_id' => ['nullable', 'integer',
                Rule::exists('sheq_competencies', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)
                        ->whereNull('deleted_at'))],
            'linked_inspection_template_id' => ['nullable', 'integer'],
            'linked_permit_type' => ['nullable', 'string', 'max:40'],
            'linked_activity' => ['nullable', 'string', 'max:60'],
        ]);

        $updated = DB::table('sheq_controls')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->update(array_merge($data, [
                'linked_activity' => empty($data['linked_activity']) ? null
                    : strtoupper(trim((string) $data['linked_activity'])),
                'updated_at' => now(),
            ]));

        if ($updated === 0) {
            return back()->withErrors(['control' => 'No such control in this company.']);
        }

        return back()->with('success', 'Linked.');
    }
}
