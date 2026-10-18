<?php

namespace App\Console\Commands\Fleet;

use App\Modules\Fleet\Integrations\FleetIntegrationManager;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class SyncFleetIntegrationsCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'fleet:sync-integrations {--provider= : Sync only this provider key} {--id= : Sync only this integration record ID} {--company= : Process only this company} {--sync : Process companies inline instead of fanning out to the queue}';
    protected $description = 'Sync data from all active fleet integrations (telematics, fuel cards, tollgates).';

    public function handle(FleetIntegrationManager $manager): int
    {
        if ($id = $this->option('id')) {
            $result = $manager->syncOne((int) $id);
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $run = fn () => $manager->syncAll();

        // One company (invoked by the fan-out job, or manually).
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, $run);
            return self::SUCCESS;
        }

        // Inline mode — original behaviour for small installs / local.
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $run());
            $this->info('[Fleet] Sync complete.');
            return self::SUCCESS;
        }

        // Default: fan out one queued job per company.
        $n = $tenant->dispatchEachCompany('fleet:sync-integrations');
        $this->info("[Fleet] Dispatched {$n} company sync job(s).");
        return self::SUCCESS;
    }
}
