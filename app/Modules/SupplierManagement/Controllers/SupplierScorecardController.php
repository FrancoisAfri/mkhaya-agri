<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierAppraisal;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierScar;
use Inertia\Inertia;
use Inertia\Response;

class SupplierScorecardController extends Controller
{
    public function show(Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        $avgScore = (float) SupplierAppraisal::where('supplier_id', $supplier->id)
            ->avg('total_score');

        $appraisalsCount = SupplierAppraisal::where('supplier_id', $supplier->id)->count();

        $latestAppraisalDate = SupplierAppraisal::where('supplier_id', $supplier->id)
            ->max('appraisal_date');

        $openScars = SupplierScar::where('supplier_id', $supplier->id)->open()->count();

        $openRiskFlags = SupplierRiskFlag::where('supplier_id', $supplier->id)
            ->where('status', 'open')
            ->count();

        $recentAppraisals = SupplierAppraisal::with('template:id,name')
            ->where('supplier_id', $supplier->id)
            ->latest('appraisal_date')
            ->limit(10)
            ->get(['id', 'template_id', 'total_score', 'status', 'appraisal_date']);

        return Inertia::render('Modules/SupplierManagement/Scorecard/Show', [
            'supplier'  => $supplier->only(['id', 'company_name', 'supplier_number']),
            'scorecard' => [
                'avg_score'             => round($avgScore, 2),
                'band'                  => $this->ratingBand($avgScore),
                'open_scars'            => $openScars,
                'open_risk_flags'       => $openRiskFlags,
                'appraisals_count'      => $appraisalsCount,
                'latest_appraisal_date' => $latestAppraisalDate,
            ],
            'recentAppraisals' => $recentAppraisals,
        ]);
    }

    private function ratingBand(float $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 50 => 'C',
            default      => 'D',
        };
    }
}
