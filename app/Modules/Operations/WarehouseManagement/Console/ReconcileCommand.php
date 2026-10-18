<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseAccounting;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/** The nightly stock-to-books reconciliation, per company (WMS-102). */
class ReconcileCommand extends Command
{
    protected $signature = 'wms:reconcile {--company= : Only this company} {--sync : Run companies inline instead of queueing one job each}';

    protected $description = 'Reconcile the stock ledger with itself and with the general ledger, and queue what disagrees';

    public function handle(TenantContext $tenant, WarehouseAccounting $accounting): int
    {
        $run = fn () => $accounting->reconcile((int) app(CurrentCompany::class)->id());
        if ($cid = $this->option('company')) {
            $r = $tenant->run((int) $cid, $run);
            $this->info("Company {$cid}: {$r['open']} open exception(s), {$r['cleared']} cleared.");

            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $tenant->eachCompany(fn () => $run());

            return self::SUCCESS;
        }
        $this->info('Queued reconciliation for '.$tenant->dispatchEachCompany('wms:reconcile').' company(ies).');

        return self::SUCCESS;
    }
}
