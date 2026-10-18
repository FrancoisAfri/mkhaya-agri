<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One risk assessment: its lines, their controls, and the signature it needs (§5.4, SHEQ-RA-1…7).
 *
 * Everything here existed in {@see RiskAssessmentService} and could not be reached. An assessment
 * could be created from the register and then nothing could be done with it — no hazard lines could
 * be added, no controls recorded, nobody could approve it, and no revision could be raised. An
 * assessment with no lines also blocks every permit that references it, so the dead end reached
 * further than this screen.
 *
 * The approval authority is the thing worth building the page around. It is derived from the
 * matrix, never chosen by the approver, so the screen shows what the assessment currently demands
 * and who may therefore sign it — which changes as lines are added.
 */
class RiskAssessmentController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly RiskAssessmentService $assessments) {}

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);
        $assessment = $this->assessment($companyId, $uuid);

        return Inertia::render('Modules/Sheq/RiskAssessments/Show', [
            'assessment' => $assessment,
            'lines' => $this->lines($companyId, (int) $assessment['id']),
            'participants' => $this->participants($companyId, (int) $assessment['id']),
            // Derived, not chosen: what the highest residual band demands of whoever signs.
            'approval' => $this->assessments->requiredApprovalRole($companyId, $uuid),
            /*
             * Where a high or critical risk is held by administrative measures or PPE alone — the
             * bottom of the hierarchy of controls. Surfaced on the page rather than left for an
             * auditor to find, because it is the finding the assessment exists to prevent.
             */
            'weakControls' => $this->assessments->weakControlFindings($companyId, $uuid),
            'matrix' => $this->matrix($companyId, $assessment['matrix_id'] ?? null),
            'can' => [
                'author' => (bool) $request->user()?->can('sheq.risk_assessment.author'),
                'approve' => (bool) $request->user()?->can('sheq.risk_assessment.author'),
            ],
        ]);
    }

    public function addLine(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.risk_assessment.author'), 403,
            'ENTITLEMENT_REQUIRED: sheq.risk_assessment.author');

        $data = $request->validate([
            'activity' => ['required', 'string', 'max:255'],
            'hazard' => ['required', 'string', 'max:500'],
            'potential_harm' => ['nullable', 'string', 'max:500'],
            'inherent_likelihood' => ['required', 'integer', 'min:1', 'max:6'],
            'inherent_severity' => ['required', 'integer', 'min:1', 'max:6'],
            'residual_likelihood' => ['nullable', 'integer', 'min:1', 'max:6'],
            'residual_severity' => ['nullable', 'integer', 'min:1', 'max:6'],
            'controls' => ['nullable', 'array'],
            'controls.*.hierarchy_level' => ['required_with:controls', 'string', 'max:40'],
            'controls.*.description' => ['required_with:controls', 'string', 'max:1000'],
        ]);

        $this->assessments->addLine($this->requireCompany($request), $uuid,
            collect($data)->except('controls')->all(), $data['controls'] ?? []);

        return back()->with('success', 'Hazard line added.');
    }

    public function addParticipant(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.risk_assessment.author'), 403,
            'ENTITLEMENT_REQUIRED: sheq.risk_assessment.author');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:120'],
            'hr_person_id' => ['nullable', 'integer'],
        ]);

        $this->assessments->addParticipant($this->requireCompany($request), $uuid, $data);

        return back()->with('success', 'Participant recorded.');
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.risk_assessment.author'), 403,
            'ENTITLEMENT_REQUIRED: sheq.risk_assessment.author');

        $data = $request->validate([
            'effective_from' => ['nullable', 'date'],
        ]);

        /*
         * The approver's roles are read from the person signing, not posted from the browser.
         *
         * Letting the client say which role it is approving as would defeat SHEQ-RA-3 entirely:
         * the whole control is that authority is derived from the matrix and matched against what
         * the signer actually holds.
         */
        $roles = $request->user()?->getRoleNames()?->map(
            fn ($role) => strtoupper(str_replace([' ', '-'], '_', (string) $role))
        )->all() ?? [];

        $this->assessments->approve($this->requireCompany($request), $uuid,
            $request->user()?->id, $roles, $data['effective_from'] ?? null);

        return back()->with('success', 'Assessment approved.');
    }

    public function revise(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.risk_assessment.author'), 403,
            'ENTITLEMENT_REQUIRED: sheq.risk_assessment.author');

        $data = $request->validate([
            'trigger' => ['nullable', 'string', 'max:40'],
        ]);

        // An approved assessment is never edited; a revision supersedes it and the original stays
        // readable, because an incident was investigated against whichever version was in force.
        $revision = $this->assessments->createRevision($this->requireCompany($request), $uuid,
            $request->user()?->id, $data['trigger'] ?? 'SCHEDULE');

        return redirect()
            ->route('sheq.risk-assessments.show', $revision['uuid'])
            ->with('success', 'Revision created. The previous version stays on the record.');
    }

    /** @return array<string,mixed> */
    private function assessment(int $companyId, string $uuid): array
    {
        $row = DB::table('sheq_risk_assessments as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->leftJoin('sheq_risk_assessments as prev', 'prev.id', '=', 'a.supersedes_id')
            ->where('a.company_id', $companyId)->where('a.uuid', $uuid)->whereNull('a.deleted_at')
            ->first([
                'a.id', 'a.uuid', 'a.reference', 'a.title', 'a.scope', 'a.ra_type', 'a.status',
                'a.version', 'a.matrix_id', 'a.effective_from', 'a.review_due', 'a.review_trigger',
                'a.approved_at', 'a.approval_band', 'a.approval_role_required',
                's.name as site_name',
                'prev.reference as supersedes_reference', 'prev.uuid as supersedes_uuid',
            ]);

        abort_if($row === null, 404);

        return (array) $row;
    }

    /** Lines with their controls, because a line without its controls cannot be judged. */
    private function lines(int $companyId, int $assessmentId): array
    {
        $lines = DB::table('sheq_risk_lines')
            ->where('company_id', $companyId)->where('risk_assessment_id', $assessmentId)
            ->whereNull('deleted_at')->orderBy('sequence')
            ->get([
                'id', 'sequence', 'activity', 'hazard', 'potential_harm',
                'inherent_likelihood', 'inherent_severity', 'inherent_score', 'inherent_band',
                'residual_likelihood', 'residual_severity', 'residual_score', 'residual_band',
                'is_acceptable',
            ]);

        $controls = DB::table('sheq_controls')
            ->where('company_id', $companyId)
            ->whereIn('risk_line_id', $lines->pluck('id'))
            ->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'risk_line_id', 'hierarchy_level', 'description', 'is_implemented'])
            ->groupBy('risk_line_id');

        return $lines->map(fn ($line) => (array) $line + [
            'controls' => $controls->get($line->id, collect())->map(fn ($c) => (array) $c)->values()->all(),
        ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function participants(int $companyId, int $assessmentId): array
    {
        return DB::table('sheq_risk_assessment_participants')
            ->where('company_id', $companyId)->where('risk_assessment_id', $assessmentId)
            ->orderBy('id')->get()->map(fn ($p) => (array) $p)->all();
    }

    /** The bands, so the screen can show what a score means rather than only the number. */
    private function matrix(int $companyId, ?int $matrixId): array
    {
        $matrix = $this->assessments->matrixFor($companyId, $matrixId);

        return [
            'name' => $matrix->name,
            'likelihood_levels' => $matrix->likelihoodLevels,
            'severity_levels' => $matrix->severityLevels,
            // The denominator. The screen showed "17 HIGH" with no sense of scale — 17 out of what
            // depends on the tenant's own matrix, which may be 3×3 or 6×6, and `maxScore()` was
            // written to answer it and called by nothing.
            'max_score' => $matrix->maxScore(),
            'likelihood_labels' => $matrix->likelihoodLabels,
            'severity_labels' => $matrix->severityLabels,
            'bands' => $matrix->bands,
        ];
    }
}
