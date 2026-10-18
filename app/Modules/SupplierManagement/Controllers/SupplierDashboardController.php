<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierScar;
use App\Modules\SupplierManagement\Models\SupplierScreening;
use App\Modules\SupplierManagement\Services\SupplierService;
use Inertia\Inertia;
use Inertia\Response;

class SupplierDashboardController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $stats = $this->suppliers->dashboardStats();

        return Inertia::render('Modules/SupplierManagement/Dashboard', [
            'stats' => [
                'total_suppliers'     => $stats['total_suppliers'],
                'pending_kyc'         => $stats['pending_kyc'],
                'performance_reviews' => $stats['performance_reviews'],
                'open_risk_flags'     => SupplierRiskFlag::open()->count(),
                'open_scars'          => SupplierScar::open()->count(),
                'high_risk_screened'  => SupplierScreening::where('risk_level', 'high')->count(),
            ],
            'recent' => $stats['recent'],
        ]);
    }
}
