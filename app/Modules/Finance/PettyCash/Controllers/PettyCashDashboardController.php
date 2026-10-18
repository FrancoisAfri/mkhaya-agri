<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashPolicyViolation;
use App\Modules\Finance\PettyCash\Models\PettyCashReconciliation;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use Inertia\Inertia;
use Inertia\Response;

class PettyCashDashboardController extends Controller
{
    public function index(): Response
    {
        $floats = PettyCashFloat::with('custodian')
            ->where('status', 'active')
            ->get();

        $stats = [
            'active_floats'         => $floats->count(),
            'total_cash_on_hand'    => $floats->sum('current_balance'),
            'total_float_limit'     => $floats->sum('float_limit'),
            'cash_issued_today'     => PettyCashRequest::whereDate('issued_at', today())
                                         ->whereIn('status', ['issued', 'settled'])
                                         ->sum('amount_issued'),
            'cash_spent_this_month' => PettyCashRequest::whereMonth('settled_at', now()->month)
                                         ->where('status', 'settled')
                                         ->sum('amount_spent'),
            'pending_requests'      => PettyCashRequest::where('status', 'pending')->count(),
            'pending_reconciliations'=> PettyCashReconciliation::where('status', 'submitted')->count(),
            'float_shortages'       => $floats->filter(fn($f) => $f->needsReplenishment())->count(),
            'missing_receipts'      => PettyCashRequest::where('status', 'issued')
                                         ->where('receipt_submitted', false)
                                         ->where('receipt_due_at', '<', now())
                                         ->count(),
            'open_violations'       => PettyCashPolicyViolation::where('status', 'open')->count(),
            'high_risk_transactions'=> PettyCashRequest::whereIn('risk_level', ['high', 'critical'])->count(),
            'replenishment_requests'=> $floats->filter(fn($f) => $f->needsReplenishment())->count(),
        ];

        $recentRequests = PettyCashRequest::with(['requestor', 'float'])
            ->whereIn('status', ['pending', 'approved', 'issued'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id'               => $r->id,
                'uuid'             => $r->uuid,
                'reference_number' => $r->reference_number,
                'requestor'        => $r->requestor?->name ?? 'Unknown',
                'float_name'       => $r->float?->name ?? 'Unknown',
                'purpose'          => $r->purpose,
                'amount'           => $r->amount_requested,
                'status'           => $r->status,
                'requested_at'     => $r->requested_at?->toISOString(),
            ]);

        $floatSummary = $floats->map(fn($f) => [
            'id'                => $f->id,
            'uuid'              => $f->uuid,
            'name'              => $f->name,
            'custodian'         => $f->custodian?->name ?? 'Unassigned',
            'current_balance'   => $f->current_balance,
            'float_limit'       => $f->float_limit,
            'utilization_pct'   => $f->utilizationPercentage(),
            'needs_replenishment'=> $f->needsReplenishment(),
            'status'            => $f->status,
        ]);

        $violations = PettyCashPolicyViolation::with('request')
            ->where('status', 'open')
            ->orderByDesc('detected_at')
            ->limit(5)
            ->get()
            ->map(fn($v) => [
                'id'          => $v->id,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'detected_at' => $v->detected_at->toISOString(),
            ]);

        return Inertia::render('Modules/Finance/PettyCash/Dashboard/Index', [
            'stats'          => $stats,
            'recentRequests' => $recentRequests,
            'floatSummary'   => $floatSummary,
            'violations'     => $violations,
        ]);
    }
}
