<?php

namespace App\Console\Commands\Communication;

use App\Modules\Communication\EmailCampaigns\Jobs\SendCampaignJob;
use App\Modules\Communication\EmailCampaigns\Models\EmailCampaign;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    use RunsPerTenant;

    protected $signature   = 'campaigns:dispatch-scheduled';
    protected $description = 'Dispatch queued email campaigns whose scheduled_at has passed';

    public function handle(): void
    {
        $this->eachTenant(app(TenantContext::class), function () {
            $due = EmailCampaign::where('status', 'scheduled')
                ->where('scheduled_at', '<=', now())
                ->get();

            foreach ($due as $campaign) {
                SendCampaignJob::dispatch($campaign);
                $this->info("Dispatched campaign #{$campaign->id}: {$campaign->title}");
            }

            if ($due->isEmpty()) {
                $this->line('No scheduled campaigns due.');
            }
        });
    }
}
