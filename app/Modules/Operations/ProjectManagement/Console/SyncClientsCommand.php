<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Services\ClientSyncService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Keep PM's clients and CRM's customers as one list (PM-1043).
 *
 * Two jobs, both idempotent:
 *
 *   refresh   names and details for clients already pointing at CRM, marking any whose account has
 *             gone as orphaned rather than blanking the project's client field.
 *   adopt     PM-local clients matched into CRM — only with --adopt, because it changes which
 *             module owns forty customer records and that is not something a nightly job should
 *             decide on a tenant's behalf. The setup page runs it on a button press.
 *
 * Per tenant, explicitly. Row-level security treats an unset company as "every row", so a command
 * that iterated clients without pinning the tenant first would adopt one company's clients into
 * another's CRM.
 */
class SyncClientsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:sync-clients
                            {--company= : One company id, instead of every tenant}
                            {--adopt : Also match PM-local clients into CRM}';

    protected $description = 'Refresh Project Management clients against CRM, and optionally adopt local ones into it.';

    public function handle(ClientSyncService $clients, TenantContext $tenants): int
    {
        $totals = ['refreshed' => 0, 'orphaned' => 0, 'adopted' => 0, 'matched' => 0];

        // The tenant list now comes from TenantContext (active + trial), which is the platform's
        // one definition of "a tenant a cron should touch". This command used to build its own from
        // `companies` minus soft-deletes, so it also synced suspended and closed customers.
        $this->eachTenant($tenants, function (int $companyId) use ($clients, &$totals) {
                if (! $clients->isIntegrated()) {
                    // No CRM on this tenant: their clients are PM's own, which is a complete and
                    // correct state, not a pending one.
                    return;
                }

                $refreshed = $clients->refreshAll();
                $totals['refreshed'] += $refreshed['refreshed'];
                $totals['orphaned'] += $refreshed['orphaned'];

                if ($this->option('adopt')) {
                    $adopted = $clients->adoptAll();
                    $totals['adopted'] += $adopted['adopted'];
                    $totals['matched'] += $adopted['matched'];
                }

                $this->line("  company {$companyId}: refreshed {$refreshed['refreshed']}, orphaned {$refreshed['orphaned']}");
        });

        $this->info(sprintf(
            'Clients: %d refreshed, %d orphaned, %d adopted, %d matched to an existing account.',
            $totals['refreshed'],
            $totals['orphaned'],
            $totals['adopted'],
            $totals['matched'],
        ));

        return self::SUCCESS;
    }
}
