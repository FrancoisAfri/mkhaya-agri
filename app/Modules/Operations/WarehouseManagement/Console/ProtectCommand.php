<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\Manufacturing\Services\LossInvestigation;
use App\Modules\Operations\WarehouseManagement\Services\RiskDetector;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/** The daily Profit Protection scan and overdue escalation, per company (WMS-110, WMS-112). */
class ProtectCommand extends Command
{
    protected $signature = 'wms:protect {--company= : Only this company} {--sync : Run companies inline instead of queueing one job each}';

    protected $description = 'Run the warehouse loss detectors and escalate overdue investigations';

    public function handle(TenantContext $tenant, RiskDetector $detector, LossInvestigation $investigation): int
    {
        $run = function () use ($detector, $investigation) {
            $cid = (int) app(CurrentCompany::class)->id();
            $raised = array_sum($detector->scan($cid));
            $investigation->escalateOverdue($cid);

            return $raised;
        };
        if ($cid = $this->option('company')) {
            $this->info("Company {$cid}: ".$tenant->run((int) $cid, $run).' new finding(s).');

            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $tenant->eachCompany(fn () => $run());

            return self::SUCCESS;
        }
        $this->info('Queued loss detection for '.$tenant->dispatchEachCompany('wms:protect').' company(ies).');

        return self::SUCCESS;
    }
}
