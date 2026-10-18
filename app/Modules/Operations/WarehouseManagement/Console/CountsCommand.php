<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\CountInsights;
use App\Modules\Operations\WarehouseManagement\Services\Counting;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * The counting calendar, per company: open the counts whose plans are due, and on the first of
 * the month re-rank items into ABC classes so the plans that count by class follow what moves.
 */
class CountsCommand extends Command
{
    protected $signature = 'wms:counts {--company= : Only this company} {--sync : Run companies inline instead of queueing one job each} {--classify : Re-rank ABC classes now}';

    protected $description = 'Open the stock counts whose plans are due, and refresh ABC classes monthly';

    public function handle(TenantContext $tenant, Counting $counting, CountInsights $insights): int
    {
        $run = function () use ($counting, $insights) {
            if ($this->option('classify') || now()->day === 1) {
                $insights->classify((int) app(CurrentCompany::class)->id());
            }

            return $counting->runDuePlans();
        };

        if ($cid = $this->option('company')) {
            $this->info("Company {$cid}: ".$tenant->run((int) $cid, $run).' count(s) opened.');

            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $tenant->eachCompany(fn () => $run());

            return self::SUCCESS;
        }
        $this->info('Queued count planning for '.$tenant->dispatchEachCompany('wms:counts').' company(ies).');

        return self::SUCCESS;
    }
}
