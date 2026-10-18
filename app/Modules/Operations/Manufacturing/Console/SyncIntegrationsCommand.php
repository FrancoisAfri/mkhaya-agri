<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Services\IntegrationHub;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/** Runs every switched-on integration that is due, and retries failed messages (MFG-022). Per tenant. */
class SyncIntegrationsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:sync-integrations {--company= : Only this company id}';

    protected $description = 'Synchronise manufacturing integrations with external ERP and industrial systems';

    protected function tenantFailureLabel(): string
    {
        return 'Manufacturing integration sync';
    }

    public function handle(TenantContext $tenants, IntegrationHub $hub): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($hub) {
            $due = Integration::query()->where('company_id', $companyId)->where('status', 'ACTIVE')->get()
                ->filter(fn (Integration $i) => ! $i->last_synced_at || ! $i->sync_every_minutes || $i->last_synced_at->lte(now()->subMinutes($i->sync_every_minutes)));
            foreach ($due as $i) {
                $r = $hub->sync($i);
                $this->line("  company {$companyId} · {$i->name}: ".json_encode($r));
            }
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
