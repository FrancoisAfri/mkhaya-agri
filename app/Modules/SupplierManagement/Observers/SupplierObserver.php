<?php

namespace App\Modules\SupplierManagement\Observers;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Services\SupplierRiskService;
use Illuminate\Support\Facades\DB;

class SupplierObserver
{
    public function created(Supplier $supplier): void
    {
        $this->scanAfterCommit($supplier);
    }

    public function updated(Supplier $supplier): void
    {
        // The scan only writes to supplier_risk_flags, never to suppliers,
        // so there is no risk of an infinite update loop.
        $this->scanAfterCommit($supplier);
    }

    /**
     * Run the risk scan AFTER the surrounding transaction commits, so a detector
     * query failure can never poison/roll back supplier creation, and the scan
     * sees committed data. Runs immediately when there is no open transaction.
     */
    private function scanAfterCommit(Supplier $supplier): void
    {
        DB::afterCommit(fn () => app(SupplierRiskService::class)->scan($supplier));
    }
}
