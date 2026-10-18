<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimRiskScore;
use App\Modules\Finance\Claims\Services\ClaimsAiService;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsAiController extends Controller
{
    use ClaimsAuthTrait;

    public function __construct(private readonly ClaimsAiService $ai) {}

    public function index(): Response
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $highRisk = Claim::with(['employee.person', 'category', 'riskScore'])
            ->whereIn('risk_level', ['high', 'critical'])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->orderByDesc('risk_score')
            ->limit(50)
            ->get()
            ->map(fn($c) => [
                'id'               => $c->id,
                'reference_number' => $c->reference_number,
                'employee'         => $c->employeeName(),
                'title'            => $c->title,
                'category'         => $c->category?->name ?? '—',
                'total_amount'     => (float) $c->total_amount,
                'status'           => $c->status,
                'risk_score'       => $c->risk_score ? (float) $c->risk_score : 0,
                'risk_level'       => $c->risk_level,
                'ai_flags'         => $c->ai_flags ?? [],
                'is_duplicate'     => $c->riskScore?->is_duplicate ?? false,
                'is_inflated'      => $c->riskScore?->is_inflated ?? false,
                'is_splitting'     => $c->riskScore?->is_splitting ?? false,
                'missing_receipt'  => $c->riskScore?->missing_receipt ?? false,
                'out_of_policy'    => $c->riskScore?->out_of_policy ?? false,
                'explanation'      => $c->riskScore?->explanation,
                'claim_date'       => $c->claim_date->format('d M Y'),
            ]);

        $stats = [
            'total_analysed'  => ClaimRiskScore::count(),
            'high_risk'       => ClaimRiskScore::whereIn('risk_level', ['high', 'critical'])->count(),
            'duplicates'      => ClaimRiskScore::where('is_duplicate', true)->count(),
            'inflated'        => ClaimRiskScore::where('is_inflated', true)->count(),
            'splitting'       => ClaimRiskScore::where('is_splitting', true)->count(),
            'missing_receipts'=> ClaimRiskScore::where('missing_receipt', true)->count(),
        ];

        return Inertia::render('Modules/Finance/Claims/AiIntelligence/Index', [
            'highRiskClaims' => $highRisk,
            'stats'          => $stats,
        ]);
    }

    public function analyseAll(): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $claims = Claim::whereNotIn('status', ['cancelled', 'draft'])->get();
        $count  = 0;

        foreach ($claims as $claim) {
            try {
                $this->ai->analyseClaimRisk($claim);
                $count++;
            } catch (\Throwable) {
                // continue
            }
        }

        return back()->with('success', "AI analysis complete — {$count} claims scored.");
    }

    public function analyseClaim(Claim $claim): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);
        $this->ai->analyseClaimRisk($claim);
        return back()->with('success', "AI analysis complete for {$claim->reference_number}.");
    }
}
