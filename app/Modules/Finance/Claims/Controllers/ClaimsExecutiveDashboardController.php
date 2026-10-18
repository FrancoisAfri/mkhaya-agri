<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimRiskScore;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsExecutiveDashboardController extends Controller
{
    use ClaimsAuthTrait;

    public function index(): Response
    {
        abort_unless($this->canViewClaimsDashboard(), 403);

        $year = now()->year;

        // ── KPI Cards ─────────────────────────────────────────────────────────
        $totalSpend = Claim::whereYear('claim_date', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->sum('total_amount');

        $totalClaims = Claim::whereYear('claim_date', $year)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->count();

        $avgClaimValue = $totalClaims > 0
            ? Claim::whereYear('claim_date', $year)
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->avg('total_amount')
            : 0;

        $approvedCount = Claim::whereYear('claim_date', $year)
            ->whereIn('status', ['approved', 'paid'])
            ->count();

        $approvalRate = $totalClaims > 0 ? round($approvedCount / $totalClaims * 100, 1) : 0;

        $avgApprovalDays = DB::table('claims')
            ->whereYear('claim_date', $year)
            ->where('status', 'approved')
            ->whereNotNull('submitted_at')
            ->whereNotNull('approved_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (approved_at::timestamp - submitted_at::timestamp)) / 86400) as avg_days")
            ->value('avg_days');

        $claimsWithViolations = DB::table('claim_policy_violations as cpv')
            ->join('claims as c', 'c.id', '=', 'cpv.claim_id')
            ->whereYear('c.claim_date', $year)
            ->distinct('cpv.claim_id')
            ->count('cpv.claim_id');

        $policyCompliance = $totalClaims > 0
            ? round((1 - $claimsWithViolations / $totalClaims) * 100, 1)
            : 100.0;

        $pendingCount = Claim::whereIn('status', ['submitted', 'under_review'])->count();

        // ── Monthly spend trend (last 12 months) ─────────────────────────────
        $monthlySpend = DB::table('claims')
            ->where('claim_date', '>=', now()->subMonths(11)->startOfMonth()->toDateString())
            ->whereIn('status', ['approved', 'paid'])
            ->selectRaw("TO_CHAR(claim_date, 'Mon YYYY') as month, DATE_TRUNC('month', claim_date::timestamp) as month_start, SUM(total_amount) as total, COUNT(*) as count")
            ->groupByRaw("TO_CHAR(claim_date, 'Mon YYYY'), DATE_TRUNC('month', claim_date::timestamp)")
            ->orderBy('month_start')
            ->get()
            ->map(fn($r) => ['month' => $r->month, 'total' => (float) $r->total, 'count' => (int) $r->count]);

        // ── By category (approved+paid YTD) ──────────────────────────────────
        $byCategory = DB::table('claims')
            ->leftJoin('claim_categories as cc', 'cc.id', '=', 'claims.category_id')
            ->whereYear('claims.claim_date', $year)
            ->whereIn('claims.status', ['approved', 'paid'])
            ->groupBy('claims.category_id', 'cc.name')
            ->orderByRaw('SUM(claims.total_amount) DESC')
            ->limit(10)
            ->get([
                DB::raw("COALESCE(cc.name, 'Uncategorised') as category"),
                DB::raw('SUM(claims.total_amount)::float as total'),
                DB::raw('COUNT(*) as count'),
            ]);

        // ── By department ─────────────────────────────────────────────────────
        $byDepartment = DB::table('claims')
            ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'claims.employee_id')
            ->join('company_units as cu', fn($j) =>
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->whereYear('claims.claim_date', $year)
            ->whereIn('claims.status', ['approved', 'paid'])
            ->groupBy('cu.id', 'cu.name')
            ->orderByRaw('SUM(claims.total_amount) DESC')
            ->limit(10)
            ->get([
                'cu.name as department',
                DB::raw('SUM(claims.total_amount)::float as total'),
                DB::raw('COUNT(*) as count'),
            ]);

        // ── Status distribution ───────────────────────────────────────────────
        $statusDist = Claim::whereYear('claim_date', $year)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // ── Risk distribution ─────────────────────────────────────────────────
        $riskDist = Claim::whereYear('claim_date', $year)
            ->whereNotNull('risk_level')
            ->selectRaw('risk_level, COUNT(*) as count')
            ->groupBy('risk_level')
            ->pluck('count', 'risk_level');

        // ── Top claimants ─────────────────────────────────────────────────────
        $topClaimants = DB::table('claims')
            ->join('hr_people as p', 'p.user_id', '=', 'claims.employee_id')
            ->whereYear('claims.claim_date', $year)
            ->whereIn('claims.status', ['approved', 'paid'])
            ->groupBy('claims.employee_id', 'p.first_name', 'p.surname')
            ->orderByRaw('SUM(claims.total_amount) DESC')
            ->limit(10)
            ->get([
                DB::raw("TRIM(p.first_name || ' ' || p.surname) as employee_name"),
                DB::raw('COUNT(*) as claim_count'),
                DB::raw('SUM(claims.total_amount)::float as total_amount'),
                DB::raw('AVG(claims.total_amount)::float as avg_amount'),
            ]);

        // ── AI Adviser ────────────────────────────────────────────────────────
        $highRiskCount = ClaimRiskScore::whereIn('risk_level', ['high', 'critical'])->count();
        $duplicateCount = ClaimRiskScore::where('is_duplicate', true)->count();

        $aiInsights = $this->buildAiInsights([
            'total_spend'       => (float) $totalSpend,
            'total_claims'      => $totalClaims,
            'approval_rate'     => $approvalRate,
            'avg_approval_days' => round((float) $avgApprovalDays, 1),
            'policy_compliance' => $policyCompliance,
            'high_risk_count'   => $highRiskCount,
            'duplicate_count'   => $duplicateCount,
            'pending_count'     => $pendingCount,
            'top_category'      => $byCategory->first()?->category ?? 'N/A',
            'top_department'    => $byDepartment->first()?->department ?? 'N/A',
            'year'              => $year,
        ]);

        return Inertia::render('Modules/Finance/Claims/Executive/Index', [
            'kpis' => [
                'total_spend_ytd'   => (float) $totalSpend,
                'total_claims_ytd'  => $totalClaims,
                'avg_claim_value'   => round((float) $avgClaimValue, 2),
                'approval_rate'     => $approvalRate,
                'avg_approval_days' => round((float) $avgApprovalDays, 1),
                'policy_compliance' => $policyCompliance,
                'pending_count'     => $pendingCount,
                'high_risk_count'   => $highRiskCount,
            ],
            'monthlySpend' => $monthlySpend,
            'byCategory'   => $byCategory,
            'byDepartment' => $byDepartment,
            'statusDist'   => $statusDist,
            'riskDist'     => $riskDist,
            'topClaimants' => $topClaimants,
            'aiInsights'   => $aiInsights,
            'year'         => $year,
        ]);
    }

    private function buildAiInsights(array $d): array
    {
        $flags           = [];
        $recommendations = [];

        if ($d['approval_rate'] < 60) {
            $flags[] = "Low approval rate of {$d['approval_rate']}% detected. A high proportion of claims are being rejected, which may indicate unclear expense policies or insufficient employee guidance.";
            $recommendations[] = "Communicate expense policies more clearly and consider adding policy hints on the claims submission form.";
        } elseif ($d['approval_rate'] > 95 && $d['total_claims'] > 20) {
            $flags[] = "Unusually high approval rate of {$d['approval_rate']}%. This may indicate insufficient review scrutiny.";
            $recommendations[] = "Consider adding a secondary review step for claims above a threshold amount.";
        }

        if ($d['avg_approval_days'] > 7) {
            $flags[] = "Average approval time is {$d['avg_approval_days']} days — above the recommended 5-business-day SLA.";
            $recommendations[] = "Review approval step configuration and enable delegation for absent approvers to prevent bottlenecks.";
        }

        if ($d['high_risk_count'] > 0) {
            $flags[] = "{$d['high_risk_count']} claims are flagged as high or critical risk by the AI engine.";
            $recommendations[] = "Review flagged claims in the AI Intelligence module before processing payment.";
        }

        if ($d['duplicate_count'] > 0) {
            $flags[] = "{$d['duplicate_count']} potential duplicate claim(s) detected.";
            $recommendations[] = "Investigate duplicate claims. Consider enforcing unique receipt validation on submission.";
        }

        if ($d['policy_compliance'] < 90) {
            $pct = round(100 - $d['policy_compliance']);
            $flags[] = "Policy compliance rate is {$d['policy_compliance']}% — {$pct}% of claims have at least one policy violation.";
            $recommendations[] = "Run a policy review session and ensure claim category limits are correctly configured in Setup.";
        }

        if ($d['pending_count'] > 15) {
            $flags[] = "{$d['pending_count']} claims are currently pending approval and may be stalling.";
            $recommendations[] = "Check the approvals queue. Assign or delegate approvers to prevent backlog.";
        }

        $totalFmt = 'R ' . number_format($d['total_spend'], 0);
        $concern  = empty($flags) ? 'No significant concerns detected — the claims process is operating within normal parameters.' : count($flags) . ' area(s) require management attention.';

        return [
            'summary'         => "{$d['year']} YTD: {$d['total_claims']} claims totalling {$totalFmt} processed. Highest spend category: {$d['top_category']}. Highest spend department: {$d['top_department']}. {$concern}",
            'flags'           => $flags,
            'recommendations' => $recommendations,
            'generated_at'    => now()->format('d M Y H:i'),
        ];
    }
}
