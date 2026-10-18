<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\PermitService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One permit to work, and the decisions that move it (§5.5, SHEQ-PTW-1…7).
 *
 * The whole lifecycle existed in {@see PermitService} — pre-issue checks, issue, approve, suspend,
 * reinstate, close, competency override — and none of it could be reached. The register listed
 * permits and stopped there, so a permit could be requested and then nothing could be done with it:
 * no issuer could see what was blocking it, nobody could issue or close one, and a permit left
 * ACTIVE after the work finished stayed that way.
 *
 * The screen is built around the pre-issue check rather than around the record, because that is the
 * question an issuer is actually answering: may this work start, and if not, what is stopping it.
 */
class PermitController extends Controller
{
    use ResolvesCompany;
    use ScopesToPermittedSites;

    public function __construct(
        private readonly PermitService $permits,
        private readonly RiskAssessmentService $assessments,
    ) {}

    /**
     * The live board a control room needs (SHEQ-PTW-6).
     *
     * `PermitService::activeNow()` was written for this and nothing called it, so there was no
     * screen that answered the control room's question: what work is live on my site right now,
     * and which of it is about to run out of permit. The register answers an administrator's
     * question instead — every permit ever raised, newest first — and a board built from that list
     * buries four live permits among two hundred closed ones.
     *
     * Three things are on here that the register does not carry:
     *
     * - **Time left**, signed, so a permit whose window has closed while work continues reads as
     *   overdue rather than as merely old.
     * - **The risk assessment in force** for the site, from `activeForSite()`. A permit is written
     *   against a HIRA; if there is no approved assessment covering the site, the authority the
     *   permit rests on does not exist, and that is a thing to know while the work is happening
     *   rather than at the next audit.
     * - **Whether a competency gap was overridden** to issue it, because that is the permit to walk
     *   out and look at first.
     *
     * Reading it also lapses anything past its window — `activeNow()` calls `expireLapsed()` — so
     * the board cannot show a permit as live when its own validity says otherwise.
     */
    public function board(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $siteId = $this->permittedSite($request, $companyId);
        $now = Carbon::now();

        $permits = $companyId === null ? [] : $this->permits->activeNow($companyId, $siteId);

        $assessments = [];

        foreach (array_unique(array_column($permits, 'site_id')) as $permitSiteId) {
            $assessment = $companyId === null ? null
                : $this->assessments->activeForSite($companyId, $permitSiteId === null ? null : (int) $permitSiteId);

            $assessments[(string) $permitSiteId] = $assessment === null ? null : [
                'uuid' => $assessment->uuid,
                'reference' => $assessment->reference,
                'title' => $assessment->title,
                'version' => $assessment->version,
                'review_due' => $assessment->review_due,
                // An assessment past its own review date is still the one in force. Saying so is
                // the point: the permit rests on it either way, and somebody should know.
                'review_overdue' => $assessment->review_due !== null
                    && Carbon::parse($assessment->review_due)->isPast(),
            ];
        }

        return Inertia::render('Modules/Sheq/Permits/Board', [
            'noCompany' => $companyId === null,
            'siteId' => $siteId,
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
            'permits' => array_map(function (array $permit) use ($now, $assessments) {
                $validTo = $permit['valid_to'] === null ? null : Carbon::parse($permit['valid_to']);

                return $permit + [
                    // Signed, so the front end never has to work out which side of the deadline a
                    // number is on. Negative means the window closed and the work has not stopped.
                    'hours_left' => $validTo === null ? null : (int) round($now->diffInHours($validTo, false)),
                    'risk_assessment' => $assessments[(string) $permit['site_id']] ?? null,
                ];
            }, $permits),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);
        $permit = $this->permit($companyId, $uuid);

        /*
         * Checks are run on view, not cached from request time.
         *
         * A competency lapses, a risk assessment falls due for review, a conflicting permit is
         * issued next door — all of that happens after the request was made. An issuer deciding
         * from a stale check is deciding from the wrong facts.
         */
        $checks = $this->permits->preIssueChecks($companyId, $uuid);

        return Inertia::render('Modules/Sheq/Permits/Show', [
            'permit' => $permit,
            'workers' => $this->workers($companyId, (int) $permit['id']),
            'approvals' => $this->approvals($companyId, (int) $permit['id']),
            'checks' => $checks,
            /*
             * Issuing, suspending, reinstating and closing all sit behind `sheq.permit.approve`.
             *
             * Permit practice distinguishes the approver from the issuer, and a separate
             * `sheq.permit.issue` would model that better. It is not used here because the
             * permission does not exist: creating one would leave it assigned to nobody, and a
             * screen gated on an entitlement no role holds is a screen that silently does nothing
             * — which is the failure this whole screen is fixing. Splitting it is a change to the
             * role presets, not to this controller.
             */
            'can' => [
                'issue' => (bool) $request->user()?->can('sheq.permit.approve'),
                'approve' => (bool) $request->user()?->can('sheq.permit.approve'),
                'close' => (bool) $request->user()?->can('sheq.permit.approve'),
                // Overriding a competency gap is accepting, by name, that somebody may do work
                // they are not currently ticketed for. It has its own entitlement for that reason.
                'override' => (bool) $request->user()?->can('sheq.permit.override'),
            ],
        ]);
    }

    public function issue(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.permit.approve'), 403, 'ENTITLEMENT_REQUIRED: sheq.permit.approve');

        $this->permits->issue($this->requireCompany($request), $uuid, $request->user()?->id);

        return back()->with('success', 'Permit issued. The work may start.');
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.permit.approve'), 403, 'ENTITLEMENT_REQUIRED: sheq.permit.approve');

        $data = $request->validate([
            'approval_id' => ['required', 'integer'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->permits->approve($this->requireCompany($request), $uuid,
            (int) $data['approval_id'], $request->user()?->id, $data['comments'] ?? null);

        return back()->with('success', 'Approval recorded.');
    }

    public function suspend(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.permit.approve'), 403, 'ENTITLEMENT_REQUIRED: sheq.permit.approve');

        // A suspension with no reason tells the crew nothing about what to fix.
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        $this->permits->suspend($this->requireCompany($request), $uuid, $data['reason'], $request->user()?->id);

        return back()->with('success', 'Permit suspended. Work must stop.');
    }

    public function reinstate(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.permit.approve'), 403, 'ENTITLEMENT_REQUIRED: sheq.permit.approve');

        $this->permits->reinstate($this->requireCompany($request), $uuid, $request->user()?->id);

        return back()->with('success', 'Permit reinstated.');
    }

    public function close(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.permit.approve'), 403, 'ENTITLEMENT_REQUIRED: sheq.permit.approve');

        $data = $request->validate([
            'closure_checks' => ['nullable', 'array'],
            'closure_checks.*' => ['boolean'],
        ]);

        $this->permits->close($this->requireCompany($request), $uuid,
            $data['closure_checks'] ?? [], $request->user()?->id);

        return back()->with('success', 'Permit closed.');
    }

    public function overrideCompetency(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.permit.override'), 403, 'ENTITLEMENT_REQUIRED: sheq.permit.override');

        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        $this->permits->overrideCompetency($this->requireCompany($request), $uuid,
            $data['reason'], $request->user()?->id);

        return back()->with('success', 'Competency gap overridden, against your name.');
    }

    /** @return array<string,mixed> */
    private function permit(int $companyId, string $uuid): array
    {
        $row = DB::table('sheq_permits as p')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'p.site_id')
            ->leftJoin('sheq_risk_assessments as ra', 'ra.id', '=', 'p.risk_assessment_id')
            ->where('p.company_id', $companyId)->where('p.uuid', $uuid)->whereNull('p.deleted_at')
            ->first([
                'p.id', 'p.uuid', 'p.reference', 'p.permit_type', 'p.status', 'p.work_description',
                'p.location_text', 'p.valid_from', 'p.valid_to', 'p.fire_watch_minutes',
                'p.precondition_checks', 'p.closure_checks', 'p.work_completed_at', 'p.activated_at',
                'p.suspended_at', 'p.suspension_reason', 'p.closed_at',
                'p.competency_overridden', 'p.competency_override_reason', 'p.competency_overridden_at',
                's.name as site_name',
                'ra.reference as risk_assessment_reference', 'ra.status as risk_assessment_status',
                'ra.uuid as risk_assessment_uuid',
            ]);

        abort_if($row === null, 404);

        // array_merge, not `+`: `+` keeps the left value where both sides name a key, so
        // neither decode applied and the permit screen received two JSON strings where it
        // expected two lists of checks.
        return array_merge((array) $row, [
            'precondition_checks' => json_decode((string) $row->precondition_checks, true) ?: [],
            'closure_checks' => json_decode((string) $row->closure_checks, true) ?: [],
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function workers(int $companyId, int $permitId): array
    {
        return DB::table('sheq_permit_workers')
            ->where('company_id', $companyId)->where('permit_id', $permitId)
            ->orderBy('id')
            ->get(['id', 'name', 'worker_role', 'competency_snapshot'])
            ->map(fn ($w) => array_merge((array) $w, [
                'competency_snapshot' => json_decode((string) $w->competency_snapshot, true) ?: [],
            ]))->all();
    }

    /** @return list<array<string,mixed>> */
    private function approvals(int $companyId, int $permitId): array
    {
        return DB::table('sheq_permit_approvals')
            ->where('company_id', $companyId)->where('permit_id', $permitId)
            ->orderBy('stage')->orderBy('id')
            ->get(['id', 'stage', 'approver_role', 'status', 'decided_at', 'comments'])
            ->map(fn ($a) => (array) $a)->all();
    }
}
