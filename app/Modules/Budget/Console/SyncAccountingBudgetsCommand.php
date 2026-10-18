<?php

namespace App\Modules\Budget\Console;

use App\Modules\Budget\Services\AccountingBudgetSyncService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Bring every accounting budget's mirror up to date.
 *
 * Saving in Finance › Accounting mirrors immediately, but that path swallows its own failures on
 * purpose — an accountant must not be blocked from saving because a downstream write hiccuped. This
 * is what repairs the difference, and it is also how a company that installs the budget module
 * *after* it has been budgeting in accounting gets its history brought across.
 */
class SyncAccountingBudgetsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'budgets:sync-accounting {--company= : Only this company id}';

    protected $description = 'Mirror Finance › Accounting budgets into the budget module that controls spending.';

    public function handle(TenantContext $tenants, AccountingBudgetSyncService $sync): int
    {
        $totals = ['created' => 0, 'updated' => 0, 'budgets' => 0];

        $run = function () use ($sync, &$totals) {
            $result = $sync->syncAll();

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        };

        if ($company = $this->option('company')) {
            $tenants->run((int) $company, $run);
        } else {
            $this->eachTenant($tenants, $run);
        }

        $this->info("{$totals['budgets']} accounting budget(s) read — {$totals['created']} mirrored, {$totals['updated']} updated.");

        return self::SUCCESS;
    }
}
