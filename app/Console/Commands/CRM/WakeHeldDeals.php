<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Services\CrmDealHoldService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Brings back every deal whose hold has expired.
 *
 * This is the half of the feature that makes the other half worth having. A rep who parks a deal
 * for six months will not remember it in six months — that is precisely why it went cold the last
 * time. So the date the client gave is the thing that wakes it: the deal returns to its own stage,
 * a task lands on the owner's list, and the owner is told. Nobody has to keep a note.
 *
 * Daily rather than hourly: a hold is measured in months, and waking a deal at 06:05 instead of
 * 14:00 changes nothing for anybody.
 */
class WakeHeldDeals extends Command
{
    use RunsPerTenant;

    protected $signature   = 'crm:wake-held-deals {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'Return held deals to the pipeline on the date the client asked for';

    public function handle(CrmDealHoldService $holds): int
    {
        $tenant = app(TenantContext::class);

        if ($cid = $this->option('company')) {
            $woken = 0;
            $tenant->run((int) $cid, function () use ($holds, &$woken) { $woken = $holds->wakeDue(); });
            $this->info("Deals returned to the pipeline: {$woken}.");

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $total = 0;
            $this->eachTenant($tenant, function () use ($holds, &$total) { $total += $holds->wakeDue(); });
            $this->info("Deals returned to the pipeline: {$total}.");
            Log::info('crm:wake-held-deals completed', ['woken' => $total]);

            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('crm:wake-held-deals');
        $this->info("Dispatched hold wake-up for {$n} company(ies).");

        return self::SUCCESS;
    }
}
