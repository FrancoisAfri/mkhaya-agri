<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentSla;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * The fulfilment heartbeat, per company: re-check allocations against stock that has since gone
 * bad (a blocked bin, an expired lot), allocate whatever is waiting in priority order, and warn
 * about orders about to miss their dispatch deadline.
 */
class FulfilmentCommand extends Command
{
    protected $signature = 'wms:fulfilment {--company= : Only this company} {--sync : Run companies inline instead of queueing one job each}';

    protected $description = 'Re-check allocations, allocate waiting orders and flag orders at risk of missing their SLA';

    public function handle(TenantContext $tenant, Allocator $allocator, FulfilmentSla $sla): int
    {
        $run = function () use ($allocator, $sla) {
            foreach (FulfilmentOrder::query()->whereIn('status', ['ALLOCATED', 'PARTIALLY_ALLOCATED', 'PICKING'])->get() as $order) {
                $allocator->reallocate($order);
            }
            foreach (FinWarehouse::query()->where('is_active', true)->where('is_wms_managed', true)->get() as $warehouse) {
                $allocator->allocateQueue($warehouse);
            }

            return $sla->checkAtRisk();
        };

        if ($cid = $this->option('company')) {
            $this->info("Company {$cid}: ".$tenant->run((int) $cid, $run).' order(s) newly at risk.');

            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $tenant->eachCompany(fn () => $run());

            return self::SUCCESS;
        }
        $this->info('Queued fulfilment checks for '.$tenant->dispatchEachCompany('wms:fulfilment').' company(ies).');

        return self::SUCCESS;
    }
}
