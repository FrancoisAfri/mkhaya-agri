<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashPolicyViolation;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use App\Modules\Finance\PettyCash\Models\PettyCashTransaction;
use App\Modules\Finance\PettyCash\Services\PettyCashAIService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PettyCashReportController extends Controller
{
    public function __construct(private PettyCashAIService $aiService) {}

    public function index(Request $request): Response
    {
        $from = $request->date('from', 'Y-m-d') ?? now()->startOfMonth();
        $to   = $request->date('to', 'Y-m-d') ?? now()->endOfMonth();

        $requestsByStatus = PettyCashRequest::whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as count, SUM(amount_requested) as total')
            ->groupBy('status')
            ->get();

        $requestsByCategory = PettyCashRequest::whereBetween('created_at', [$from, $to])
            ->whereNotNull('category')
            ->selectRaw('category, COUNT(*) as count, SUM(amount_requested) as total')
            ->groupBy('category')
            ->get();

        $floatActivity = PettyCashFloat::with('custodian')
            ->withSum(['requests as total_requested' => fn($q) => $q->whereBetween('created_at', [$from, $to])], 'amount_requested')
            ->withSum(['requests as total_issued' => fn($q) => $q->whereBetween('issued_at', [$from, $to])], 'amount_issued')
            ->get()
            ->map(fn($f) => [
                'float_name'     => $f->name,
                'custodian'      => $f->custodian?->name,
                'current_balance'=> $f->current_balance,
                'total_requested'=> $f->total_requested ?? 0,
                'total_issued'   => $f->total_issued ?? 0,
            ]);

        $violations = PettyCashPolicyViolation::whereBetween('detected_at', [$from, $to])
            ->selectRaw('violation_type, severity, COUNT(*) as count')
            ->groupBy('violation_type', 'severity')
            ->get();

        $floats = PettyCashFloat::where('status', 'active')->get(['id', 'name']);

        return Inertia::render('Modules/Finance/PettyCash/Reports/Index', [
            'filters'            => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'requestsByStatus'   => $requestsByStatus,
            'requestsByCategory' => $requestsByCategory,
            'floatActivity'      => $floatActivity,
            'violations'         => $violations,
            'floats'             => $floats,
        ]);
    }

    public function aiReport(Request $request, PettyCashFloat $float): \Illuminate\Http\JsonResponse
    {
        $report = $this->aiService->generatePettyCashNarrativeReport($float, $request->only('period'));

        return response()->json($report);
    }
}
