<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\Notifier;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/** Looks for what people need to hear about and tells them, once (MFG-020). Per tenant. */
class SendAlertsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:send-alerts {--company= : Only this company id}';

    protected $description = 'Send manufacturing alerts: shortages, deadlines, stalls, quality, breakdowns, approvals, actions';

    protected function tenantFailureLabel(): string
    {
        return 'Manufacturing alerts';
    }

    public function handle(TenantContext $tenants, Notifier $notifier): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($notifier) {
            $s = $notifier->run($companyId);
            $this->line("  company {$companyId}: {$s['sent']} sent, {$s['suppressed']} suppressed, {$s['escalated']} escalated, {$s['resolved']} resolved");
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
