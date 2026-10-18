<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryAiEvidenceReport;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCaseTimeline;
use App\Modules\Disciplinary\Models\DisciplinaryEvidenceAnalysis;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use App\Modules\Disciplinary\Services\DisciplinaryEvidenceIntelligenceService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DisciplinaryEvidenceIntelligenceController extends Controller
{
    use DisciplinaryAuthTrait, FileStorage;

    public function __construct(protected DisciplinaryEvidenceIntelligenceService $service) {}

    // ── Case timeline ──────────────────────────────────────────────────────────

    public function caseTimeline(DisciplinaryCase $case): JsonResponse
    {
        $role = $this->caseViewerRole($case);
        abort_if($role === 'none', 403);

        $timeline = $this->service->buildCaseTimeline($case);

        return response()->json($timeline);
    }

    // ── Witness consistency analysis for a case ────────────────────────────────

    public function analyzeWitnessConsistency(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $case->load(['witnesses']);
        $this->service->analyzeWitnessConsistency($case);

        return back()->with('success', 'Witness consistency analysis complete.');
    }

    // ── Witness consistency analysis for an incident ───────────────────────────

    public function analyzeIncidentWitnesses(DisciplinaryIncident $incident): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $incident->load(['witnesses']);
        $this->service->analyzeWitnessConsistency($incident);

        return back()->with('success', 'Witness consistency analysis complete.');
    }

    // ── Evidence summary for a case ────────────────────────────────────────────

    public function caseEvidenceSummary(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->service->generateEvidenceSummary($case);

        return back()->with('success', 'Evidence summary generated.');
    }

    // ── Evidence summary for an incident ──────────────────────────────────────

    public function incidentEvidenceSummary(DisciplinaryIncident $incident): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->service->generateEvidenceSummary($incident);

        return back()->with('success', 'Evidence summary generated.');
    }

    // ── Hearing pack for a case ────────────────────────────────────────────────

    public function generateHearingPack(DisciplinaryCase $case): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->service->generateHearingPack($case);

        return back()->with('success', 'Hearing pack generated.');
    }

    // ── Fetch stored AI reports for a case (JSON) ──────────────────────────────

    public function caseReports(DisciplinaryCase $case): JsonResponse
    {
        $role = $this->caseViewerRole($case);
        abort_if($role === 'none', 403);

        $reports = DisciplinaryAiEvidenceReport::where('case_id', $case->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => [
                'id'               => $r->id,
                'report_type'      => $r->reportTypeLabel(),
                'title'            => $r->title,
                'executive_summary'=> $r->executive_summary,
                'sections'         => $r->sections ?? [],
                'recommendations'  => $r->recommendations,
                'ai_model'         => $r->ai_model,
                'has_file'         => $r->hasBytes(),
                'download_url'     => $r->hasBytes() ? route('disciplinary.intelligence.report.download', $r->id) : null,
                'created_at'       => $r->created_at?->format('d M Y H:i'),
            ]);

        $analysis = DisciplinaryEvidenceAnalysis::where('case_id', $case->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($a) => [
                'id'                 => $a->id,
                'analysis_type'      => ucwords(str_replace('_', ' ', $a->analysis_type)),
                'analysis_result'    => $a->analysis_result,
                'key_findings'       => $a->key_findings ?? [],
                'consistency_score'  => $a->consistency_score,
                'conflicts_detected' => $a->conflicts_detected ?? [],
                'ai_model'           => $a->ai_model,
                'created_at'         => $a->created_at?->format('d M Y H:i'),
            ]);

        return response()->json(compact('reports', 'analysis'));
    }

    // ── Download stored AI report file ─────────────────────────────────────────

    public function downloadReport(DisciplinaryAiEvidenceReport $report): \Illuminate\Http\Response
    {
        $role = $report->case_id
            ? $this->caseViewerRole($report->case)
            : 'admin';
        abort_if($role === 'none', 403);
        abort_unless($report->hasBytes(), 404);

        return $report->fileResponse($report->original_name ?? 'report.html', $report->mime);
    }
}
