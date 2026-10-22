<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\MachineData\MachineDataSync;
use App\Modules\Agri\Models\MachineConnection;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Every hour, for each organisation connected to John Deere Operations Center or CNH FieldOps:
 * fields and boundaries, machines, engine hours and positions, and new field operations in;
 * the status of prescriptions sent. Each run is an integration message, so a failed one is retried
 * with back-off by agri:integrations-retry; nothing is imported twice.
 */
class SyncMachineData extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:machine-data-sync {--company= : Only this organisation}';

    protected $description = 'Sync Mkhaya Agri with John Deere Operations Center and CNH FieldOps (fields, machines, operations, prescriptions)';

    public function handle(TenantContext $tenants, MachineDataSync $sync): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($sync) {
            foreach (MachineConnection::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'connected')->whereNotNull('org_id')->get() as $c) {
                $e = $sync->sync($c, 'schedule');
                $this->line("Company {$companyId} {$c->provider}: {$e->status}".($e->error ? " — {$e->error}" : ''));
            }
        });

        return self::SUCCESS;
    }
}
