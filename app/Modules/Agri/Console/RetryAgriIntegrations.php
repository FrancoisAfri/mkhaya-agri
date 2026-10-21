<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Services\Integrations;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Every fifteen minutes (Phase 2 §14): retry the integration messages that failed and whose
 * back-off has passed. After five attempts a message is dead and waits for an administrator.
 */
class RetryAgriIntegrations extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:integrations-retry {--company= : Only this organisation}';

    protected $description = 'Retry failed Mkhaya Agri integration messages';

    public function handle(TenantContext $tenants, Integrations $integrations): int
    {
        $this->eachTenant($tenants, fn (int $companyId) => $this->line("Company {$companyId}: ".$integrations->retryDue($companyId).' retried.'));

        return self::SUCCESS;
    }
}
