<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryAiSummary;
use App\Modules\Disciplinary\Services\DisciplinaryExecutiveAiService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryExecutiveAiController extends Controller
{
    use DisciplinaryAuthTrait;

    public function __construct(private readonly DisciplinaryExecutiveAiService $ai) {}

    public function index(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $summaries = DisciplinaryAiSummary::whereIn('summary_type', [
            'executive_summary', 'misconduct_trend', 'compliance_insights',
            'behavioral_risk', 'corrective_effectiveness',
        ])
            ->where('subject_type', 'organisation')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('summary_type')
            ->map(fn($group) => $group->first())
            ->map(fn($s) => [
                'id'             => $s->id,
                'summary_type'   => $s->summary_type,
                'summary'        => $s->summary,
                'recommendation' => (function($r) {
                    if (is_array($r)) return $r;
                    if (is_string($r) && str_starts_with(trim($r), '[')) {
                        $decoded = json_decode($r, true);
                        return is_array($decoded) ? $decoded : [$r];
                    }
                    return $r ? [$r] : [];
                })($s->recommendation),
                'ai_metadata'    => $s->ai_metadata ?? [],
                'ai_model'       => $s->ai_model,
                'acknowledged'   => $s->acknowledged,
                'generated_at'   => $s->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Modules/Disciplinary/ExecutiveAiInsights/Index', [
            'summaries' => $summaries,
        ]);
    }

    public function generateSummary(): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->ai->generateDisciplinarySummary();
        return back()->with('success', 'Executive disciplinary summary generated.');
    }

    public function generateTrendAnalysis(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $months = (int) ($request->input('months', 6));
        $this->ai->generateMisconductTrendAnalysis($months);
        return back()->with('success', 'Misconduct trend analysis generated.');
    }

    public function generateComplianceInsights(): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->ai->generateComplianceInsights();
        return back()->with('success', 'Compliance insights generated.');
    }

    public function generateBehavioralRisk(): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->ai->generateBehavioralRiskSummary();
        return back()->with('success', 'Behavioral risk summary generated.');
    }

    public function generateCorrectiveReport(): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);
        $this->ai->generateCorrectiveActionEffectivenessReport();
        return back()->with('success', 'Corrective action effectiveness report generated.');
    }

    public function generateAll(): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $this->ai->generateDisciplinarySummary();
        $this->ai->generateMisconductTrendAnalysis();
        $this->ai->generateComplianceInsights();
        $this->ai->generateBehavioralRiskSummary();
        $this->ai->generateCorrectiveActionEffectivenessReport();

        return back()->with('success', 'All AI workforce intelligence reports generated.');
    }
}
