<?php

namespace App\Modules\Finance\Tax\Console;

use App\Modules\Finance\Tax\Support\TaxEvents;
use Illuminate\Console\Command;

/**
 * Delivers the tax event outbox (§19) to the application's listeners. Runs across tenants without a
 * tenant context: the events carry their company id, and listeners act per event.
 */
class DeliverTaxEventsCommand extends Command
{
    protected $signature = 'tax:events {--limit=500}';

    protected $description = 'Deliver pending tax events to their listeners, with retries';

    public function handle(TaxEvents $events): int
    {
        $s = $events->deliver((int) $this->option('limit'));
        $this->info("Tax events delivered: {$s['delivered']}, failed this run: {$s['failed']}.");

        return self::SUCCESS;
    }
}
