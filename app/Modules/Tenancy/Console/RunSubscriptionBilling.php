<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Services\Edition;
use App\Modules\Tenancy\Services\SubscriptionBilling;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Monthly SaaS billing run: raise this month's invoices, then flag overdue and
 * auto-suspend past grace. No-op in the self-hosted edition.
 */
class RunSubscriptionBilling extends Command
{
    protected $signature = 'tenancy:run-billing {--month=}';

    protected $description = 'Raise monthly subscription invoices and process overdue accounts';

    public function handle(SubscriptionBilling $billing): int
    {
        if (! Edition::isSaas()) {
            $this->info('Self-hosted edition — subscription billing skipped.');

            return self::SUCCESS;
        }

        $month = $this->option('month') ? Carbon::parse($this->option('month')) : null;
        $raised = $billing->generateMonthly($month);
        $overdue = $billing->processOverdue();

        $this->info("Raised {$raised} invoice(s). Overdue: {$overdue['overdue']}, auto-suspended: {$overdue['suspended']}.");

        return self::SUCCESS;
    }
}
