<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseApprovals;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseNotifier;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/** Detect, notify, resolve, escalate and retry warehouse alerts, and escalate overdue approvals, per company (WMS-190..192). */
class NotifyCommand extends Command
{
    protected $signature = 'wms:notify {--company= : Only this company} {--sync : Run companies inline instead of queueing one job each}';

    protected $description = 'Warehouse notifications and approval escalation';

    public function handle(TenantContext $tenant, WarehouseNotifier $notifier, WarehouseApprovals $approvals): int
    {
        $one = function () use ($notifier, $approvals) {
            $cid = (int) app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
            $approvals->escalateOverdue($cid);

            return $notifier->run($cid);
        };
        if ($cid = $this->option('company')) {
            $done = $tenant->run((int) $cid, $one);
            $this->info("Company {$cid}: ".collect($done)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));

            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $tenant->eachCompany($one);

            return self::SUCCESS;
        }
        $this->info('Queued notification runs for '.$tenant->dispatchEachCompany('wms:notify').' company(ies).');

        return self::SUCCESS;
    }
}
