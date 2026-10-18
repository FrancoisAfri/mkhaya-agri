<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaAiInsight;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Services\PaAiIntelligenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaAiInsightController extends Controller
{
    public function __construct(private PaAiIntelligenceService $ai) {}

    public function index(Request $request): Response
    {
        $cycleId = $request->query('cycle_id');

        $query = PaAiInsight::with(['cycle', 'employee.person'])
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at');

        return Inertia::render('Modules/PerformanceAppraisal/AI/Insights', [
            'insights' => $query->get()->map(fn($i) => [
                'id'              => $i->id,
                'employee_id'     => $i->employee_id,
                'employee_name'   => trim(($i->employee?->person?->first_name ?? '') . ' ' . ($i->employee?->person?->surname ?? '')) ?: "User #{$i->employee_id}",
                'cycle_id'        => $i->cycle_id,
                'cycle_name'      => $i->cycle?->name,
                'insight_type'    => $i->insight_type,
                'severity'        => $i->severity,
                'title'           => $i->title,
                'body'            => $i->body,
                'data'            => $i->data,
                'acknowledged_at' => $i->acknowledged_at?->format('d M Y H:i'),
                'created_at'      => $i->created_at->format('d M Y H:i'),
            ]),
            'cycles'          => PaCycle::orderByDesc('start_date')->get(['id', 'name', 'status']),
            'selectedCycleId' => $cycleId ? (int) $cycleId : null,
            'summary' => [
                'total'    => PaAiInsight::when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))->count(),
                'critical' => PaAiInsight::when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))->where('severity', 'critical')->count(),
                'warning'  => PaAiInsight::when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))->where('severity', 'warning')->count(),
                'unread'   => PaAiInsight::when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))->unacknowledged()->count(),
            ],
        ]);
    }

    public function acknowledge(Request $request, PaAiInsight $insight): RedirectResponse
    {
        $insight->update([
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        return back()->with('success', 'Insight acknowledged.');
    }

    public function runAnalysis(Request $request, PaCycle $cycle): RedirectResponse
    {
        $result = $this->ai->analyzePerformanceTrends($cycle);

        return back()->with('success', "Analysis complete — {$result['insights_generated']} insight(s) generated.");
    }

    public function runBiasDetection(Request $request, PaCycle $cycle): RedirectResponse
    {
        $count = $this->ai->detectManagerScoringBias($cycle);

        return back()->with('success', "{$count} scoring bias insight(s) flagged.");
    }

    public function runInconsistencyDetection(Request $request, PaCycle $cycle): RedirectResponse
    {
        $count = $this->ai->detectInconsistentScoring($cycle);

        return back()->with('success', "{$count} inconsistency insight(s) flagged.");
    }
}
