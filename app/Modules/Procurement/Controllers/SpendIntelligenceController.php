<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\ProcurementInsight;
use App\Modules\Procurement\Models\ProcurementRiskScore;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\RiskScoringService;
use App\Modules\Procurement\Services\SpendIntelligenceService;
use App\Modules\Procurement\Services\SpendNarrativeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SpendIntelligenceController extends Controller
{
    private const NARRATIVE_CACHE = 'procurement.spend_narrative';

    public function __construct(
        private readonly SpendIntelligenceService $engine,
        private readonly RiskScoringService $scoring,
        private readonly SpendNarrativeService $narrative,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $insights = ProcurementInsight::query()
            ->filter($request->only('type', 'severity', 'category', 'status'))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderByDesc('amount_impact')
            ->limit(200)->get();

        $open = ProcurementInsight::open()->get();

        return Inertia::render('Modules/Procurement/Intelligence/Dashboard', [
            'filters'  => $request->only('type', 'severity', 'category', 'status'),
            'insights' => $insights->map(fn (ProcurementInsight $i) => [
                'id' => $i->id, 'type' => $i->type, 'category' => $i->category, 'severity' => $i->severity,
                'score' => $i->score, 'title' => $i->title, 'description' => $i->description,
                'amount_impact' => $i->amount_impact, 'status' => $i->status,
                'entity_type' => $i->entity_type, 'entity_id' => $i->entity_id,
            ]),
            'kpis' => [
                'open'             => $open->count(),
                'risk'             => $open->where('category', 'risk')->count(),
                'critical'         => $open->where('severity', 'critical')->count(),
                'high'             => $open->where('severity', 'high')->count(),
                'risk_exposure'    => round((float) $open->where('category', 'risk')->sum('amount_impact'), 2),
                'savings'          => round((float) $open->where('category', 'saving')->sum('amount_impact'), 2),
                'forecast_overrun' => round((float) $open->where('category', 'forecast')->sum('amount_impact'), 2),
            ],
            'byType'     => $open->groupBy('type')->map->count(),
            'bySeverity' => [
                'critical' => $open->where('severity', 'critical')->count(),
                'high'     => $open->where('severity', 'high')->count(),
                'medium'   => $open->where('severity', 'medium')->count(),
                'low'      => $open->where('severity', 'low')->count(),
            ],
            'riskScores' => [
                'overall'   => ProcurementRiskScore::where('scope_type', 'overall')->first(),
                'suppliers' => ProcurementRiskScore::where('scope_type', 'supplier')->orderByDesc('score')->limit(10)->get(),
                'budgets'   => ProcurementRiskScore::where('scope_type', 'budget')->orderByDesc('score')->limit(10)->get(),
            ],
            'narrative' => Cache::get(self::NARRATIVE_CACHE),
            'types'     => ['duplicate', 'split', 'inflated_price', 'off_contract', 'emergency_abuse', 'dependency', 'budget_leakage', 'saving', 'forecast'],
        ]);
    }

    public function run(): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);

        $res = $this->engine->run();
        $this->scoring->recompute();

        return back()->with('success', "Analysis complete — {$res['count']} insights across ".count($res['by_type']).' categories.');
    }

    public function generateNarrative(): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);

        $result = $this->narrative->generate(Auth::id());
        Cache::put(self::NARRATIVE_CACHE, [
            'text'       => $result['narrative'],
            'provider'   => $result['provider'],
            'model'      => $result['model'],
            'generated'  => now()->toDateTimeString(),
        ], now()->addDays(7));

        return back()->with('success', 'Executive briefing generated.');
    }

    public function updateInsight(Request $request, ProcurementInsight $insight): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate(['status' => ['required', 'in:open,reviewed,dismissed,actioned']]);
        $insight->update([
            'status'      => $data['status'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Insight updated.');
    }
}
