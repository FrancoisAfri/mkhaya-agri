<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\WarehouseManagement\Services\LedgerVerifier;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-212: is the machinery running, and does the ledger agree with what the screens show? */
class HealthController extends Controller
{
    public function index(Request $request, WarehouseHealth $health, LedgerVerifier $ledger): Response
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE) || $request->user()->can(RoleCatalog::ACCOUNTING), 403);
        $cid = (int) app(CurrentCompany::class)->id();

        return Inertia::render('Modules/Warehouse/Governance/Health', [
            'checks' => $health->checks($cid),
            'ledger' => $request->boolean('verify') ? $ledger->verify($cid) : null,
        ]);
    }
}
