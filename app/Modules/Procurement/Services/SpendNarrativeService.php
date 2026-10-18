<?php

namespace App\Modules\Procurement\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Procurement\Models\ProcurementInsight;
use App\Modules\Procurement\Models\ProcurementRiskScore;
use App\Shared\DTOs\AgentRequest;

/**
 * Turns the computed insights + risk scores into a concise narrative executive
 * summary via the AI agent. Falls back to a deterministic summary if AI is off.
 */
class SpendNarrativeService
{
    public function __construct(private readonly AgentRegistry $agents) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You are a Chief Procurement Officer's analyst. Given JSON metrics about procurement
risk findings, savings and forecasts, write a concise executive briefing in GitHub
Markdown. Use these sections: "## Summary" (2-3 sentences), "## Top risks"
(bulleted, most material first), "## Savings opportunities" (bulleted), and
"## Recommended actions" (numbered, concrete). Be specific with amounts. Do not
invent data not present in the input.
TXT;

    public function generate(?int $userId = null): array
    {
        $metrics = $this->metrics();

        try {
            $response = $this->agents->for('procurement')->ask(new AgentRequest(
                prompt: "Write the procurement executive briefing from this data:\n\n".json_encode($metrics, JSON_PRETTY_PRINT),
                module: 'procurement.spend-narrative',
                context: ['totals' => $metrics['totals'] ?? []],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.3,
                maxTokens: 1800,
                responseFormat: 'text',
            ));

            if (! $response->failed() && trim((string) $response->content) !== '') {
                return ['ok' => true, 'narrative' => $response->content, 'provider' => $response->provider, 'model' => $response->model, 'metrics' => $metrics];
            }
        } catch (\Throwable) {
            // fall through to deterministic summary
        }

        return ['ok' => true, 'narrative' => $this->fallback($metrics), 'provider' => null, 'model' => null, 'metrics' => $metrics];
    }

    private function metrics(): array
    {
        $open = ProcurementInsight::open()->get();

        return [
            'totals' => [
                'open_insights'      => $open->count(),
                'risk_findings'      => $open->where('category', 'risk')->count(),
                'savings_found'      => round((float) $open->where('category', 'saving')->sum('amount_impact'), 2),
                'forecast_overruns'  => $open->where('category', 'forecast')->count(),
                'critical'           => $open->where('severity', 'critical')->count(),
                'high'               => $open->where('severity', 'high')->count(),
                'risk_exposure'      => round((float) $open->where('category', 'risk')->sum('amount_impact'), 2),
            ],
            'top_risks' => $open->where('category', 'risk')->sortByDesc('score')->take(8)
                ->map(fn ($i) => ['type' => $i->type, 'severity' => $i->severity, 'title' => $i->title, 'impact' => (float) $i->amount_impact])->values()->all(),
            'savings' => $open->where('category', 'saving')->sortByDesc('amount_impact')->take(8)
                ->map(fn ($i) => ['title' => $i->title, 'saving' => (float) $i->amount_impact])->values()->all(),
            'forecasts' => $open->where('category', 'forecast')
                ->map(fn ($i) => ['title' => $i->title, 'overrun' => (float) $i->amount_impact])->values()->all(),
            'overall_risk' => optional(ProcurementRiskScore::where('scope_type', 'overall')->first())->only(['score', 'band']),
        ];
    }

    private function fallback(array $m): string
    {
        $t = $m['totals'];
        $lines = ['## Summary',
            "{$t['open_insights']} open findings: {$t['risk_findings']} risks ({$t['critical']} critical, {$t['high']} high), "
            .'R'.number_format($t['savings_found'], 2).' in savings identified, '."{$t['forecast_overruns']} budgets trending over.",
            '', '## Top risks'];
        foreach ($m['top_risks'] as $r) {
            $lines[] = "- [{$r['severity']}] {$r['title']} (R".number_format($r['impact'], 2).')';
        }
        $lines[] = '';
        $lines[] = '## Savings opportunities';
        foreach ($m['savings'] as $s) {
            $lines[] = "- {$s['title']} (R".number_format($s['saving'], 2).')';
        }
        if (empty($m['savings'])) {
            $lines[] = '- None identified.';
        }

        return implode("\n", $lines);
    }
}
