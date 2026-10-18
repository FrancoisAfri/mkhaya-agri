<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimPolicyViolation;
use App\Modules\Finance\Claims\Models\ClaimRiskScore;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsDashboardController extends Controller
{
    use ClaimsAuthTrait;

    public function index(): Response
    {
        $userId  = Auth::id();
        $isAdmin = $this->isClaimsAdmin();

        $base = $isAdmin ? Claim::query() : Claim::where('employee_id', $userId);

        // ── Phase 4 Widgets ───────────────────────────────────────────────────
        $widgets = [
            'submitted'       => (clone $base)->where('status', 'submitted')->count(),
            'pending_approval'=> (clone $base)->whereIn('status', ['submitted', 'under_review'])->count(),
            'approved'        => (clone $base)->where('status', 'approved')->count(),
            'rejected'        => (clone $base)->where('status', 'rejected')->count(),
            'paid'            => (clone $base)->where('status', 'paid')->count(),
            'high_risk'       => $isAdmin
                ? Claim::whereIn('risk_level', ['high', 'critical'])->whereNotIn('status', ['cancelled', 'rejected'])->count()
                : 0,
            'duplicates'      => $isAdmin
                ? ClaimRiskScore::where('is_duplicate', true)->count()
                : 0,
            'policy_violations'=> $isAdmin
                ? ClaimPolicyViolation::whereHas('claim', fn($q) => $q->whereNotIn('status', ['cancelled', 'rejected']))->count()
                : 0,
        ];

        // ── Cost by department (admin only) ───────────────────────────────────
        $costByDepartment = [];
        if ($isAdmin) {
            $costByDepartment = Claim::join('hr_people as p', 'p.user_id', '=', 'claims.employee_id')
                ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'claims.employee_id')
                ->join('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                ->whereIn('claims.status', ['approved', 'paid'])
                ->groupBy('cu.name')
                ->orderByRaw('SUM(claims.total_amount) DESC')
                ->limit(8)
                ->get([DB::raw('cu.name as department'), DB::raw('SUM(claims.total_amount) as total'), DB::raw('COUNT(*) as count')])
                ->toArray();
        }

        // ── Recent claims ─────────────────────────────────────────────────────
        $recentClaims = (clone $base)
            ->with(['employee.person', 'category'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id'               => $c->id,
                'reference_number' => $c->reference_number,
                'employee'         => $c->employeeName(),
                'title'            => $c->title,
                'category'         => $c->category?->name ?? '—',
                'total_amount'     => (float) $c->total_amount,
                'currency'         => $c->currency,
                'status'           => $c->status,
                'status_color'     => $c->status_color,
                'risk_level'       => $c->risk_level,
                'claim_date'       => $c->claim_date->format('d M Y'),
                'submitted_at'     => $c->submitted_at?->format('d M Y'),
            ]);

        return Inertia::render('Modules/Finance/Claims/Dashboard/Index', [
            'widgets'          => $widgets,
            'costByDepartment' => $costByDepartment,
            'recentClaims'     => $recentClaims,
            'isAdmin'          => $isAdmin,
        ]);
    }
}
