<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Jobs\SendCampaignJob;
use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Dispatches scheduled campaigns whose send time has arrived.
 */
class RunCrmCampaigns extends Command
{
    use RunsPerTenant;

    protected $signature   = 'crm:run-campaigns';
    protected $description = 'Send any CRM campaigns scheduled for now or earlier';

    public function handle(): int
    {
        // Per-company: RLS scopes the due-campaign query, and dispatching inside
        // the tenant context stamps the job payload so SendCampaignJob runs as the
        // right tenant.
        $count = 0;
        $this->eachTenant(app(TenantContext::class), function () use (&$count) {
            $due = CrmCampaign::where('status', 'scheduled')
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now())
                ->get();

            foreach ($due as $campaign) {
                $campaign->update(['status' => 'sending']);
                // daily_cap drives the drip: the job sends that many, then reschedules.
                SendCampaignJob::dispatch($campaign->id, $campaign->daily_cap);
                $this->info("Dispatched campaign: {$campaign->name}");
                $count++;
            }
        });

        $this->info("Scheduled campaigns dispatched: {$count}.");

        return self::SUCCESS;
    }
}
