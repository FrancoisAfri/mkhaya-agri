<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Models\ProcurementInsight;
use App\Modules\Procurement\Models\ProcurementRiskScore;

/**
 * Rolls the open insights up into composite 0-100 risk scores per supplier, per
 * budget and for the overall procurement function. Higher = riskier.
 */
class RiskScoringService
{
    private const WEIGHT = ['critical' => 30, 'high' => 20, 'medium' => 10, 'low' => 4];

    public function recompute(): array
    {
        $open = ProcurementInsight::open()->where('category', 'risk')->get();

        $this->scoreScope('supplier', $open->where('entity_type', 'supplier'));
        $this->scoreScope('budget', $open->where('entity_type', 'budget'));
        $this->scoreOverall($open);

        return [
            'suppliers' => ProcurementRiskScore::where('scope_type', 'supplier')->count(),
            'budgets'   => ProcurementRiskScore::where('scope_type', 'budget')->count(),
        ];
    }

    private function scoreScope(string $scopeType, $insights): void
    {
        foreach ($insights->groupBy('entity_id') as $entityId => $group) {
            if (! $entityId) {
                continue;
            }
            $score   = min(100, $group->sum(fn ($i) => self::WEIGHT[$i->severity] ?? 5));
            $factors = $group->map(fn ($i) => ['type' => $i->type, 'severity' => $i->severity, 'title' => $i->title])->values()->all();

            ProcurementRiskScore::updateOrCreate(
                ['scope_type' => $scopeType, 'scope_id' => $entityId],
                [
                    'label'       => $group->first()->title,
                    'score'       => $score,
                    'band'        => $this->band($score),
                    'factors'     => json_encode($factors),
                    'computed_at' => now(),
                ]
            );
        }
    }

    private function scoreOverall($open): void
    {
        $score = min(100,
            $open->where('severity', 'critical')->count() * 25
            + $open->where('severity', 'high')->count() * 15
            + $open->where('severity', 'medium')->count() * 5
            + $open->where('severity', 'low')->count() * 1
        );

        ProcurementRiskScore::updateOrCreate(
            ['scope_type' => 'overall', 'scope_id' => null],
            [
                'label'       => 'Overall procurement risk',
                'score'       => $score,
                'band'        => $this->band($score),
                'factors'     => json_encode([
                    'critical' => $open->where('severity', 'critical')->count(),
                    'high'     => $open->where('severity', 'high')->count(),
                    'medium'   => $open->where('severity', 'medium')->count(),
                    'low'      => $open->where('severity', 'low')->count(),
                ]),
                'computed_at' => now(),
            ]
        );
    }

    private function band(float $score): string
    {
        return match (true) {
            $score >= 85 => 'critical',
            $score >= 60 => 'high',
            $score >= 30 => 'medium',
            default      => 'low',
        };
    }
}
