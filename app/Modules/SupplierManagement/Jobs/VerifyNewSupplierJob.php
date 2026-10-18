<?php

namespace App\Modules\SupplierManagement\Jobs;

use App\Modules\SupplierManagement\DueDiligence\Services\OnboardingVerification;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Verify a supplier that has just registered.
 *
 * Queued rather than run inline, because the registration form is a public endpoint and the checks
 * are half a dozen calls to other people's servers. Doing them in the request would make an
 * applicant wait on SARS, and a provider that hangs would lose a long form they would have to fill
 * in again. The supplier is saved and inactive before this runs, so nothing is waiting on it except
 * the buyer's view of the evidence.
 *
 * Carries the company id because a queue worker has no tenant: without it every query runs with no
 * company set, and tenant-scoped reads fail closed — returning nothing and reporting success, which
 * would show as a supplier that passed every check by having none to run.
 */
class VerifyNewSupplierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt. A retry would re-run paid lookups against providers that bill per call, and the
     * nightly sweep picks up anything that did not complete.
     */
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $supplierId, public ?int $companyId = null)
    {
    }

    public function handle(TenantContext $tenant, OnboardingVerification $verification): void
    {
        try {
            $tenant->run($this->companyId, function () use ($verification) {
                $supplier = Supplier::find($this->supplierId);

                if (! $supplier) {
                    return;
                }

                $verification->run($supplier);
            });
        } catch (\Throwable $e) {
            // Nothing downstream waits on this. The supplier is inactive either way, and the
            // nightly sweep will reach it.
            Log::warning('new supplier verification failed', [
                'supplier' => $this->supplierId, 'company' => $this->companyId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
