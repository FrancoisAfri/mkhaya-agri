<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\Finance\Accounting\Services\FinancePoster;
use Illuminate\Console\Command;

/**
 * Drains the Finance outbox — retries any pending/failed posting. Postings are normally written
 * straight through on emit; this is the safety net for any that failed transiently. Schedule it
 * every few minutes.
 *
 * Deliberately *not* a per-company fan-out. An outbox is a queue, and a drain that could only see
 * one tenant's backlog at a time would leave every other tenant's rows stuck behind whichever
 * company the run happened to be scoped to. The tenancy lives one level down instead: each row is
 * processed as its own company (see FinancePoster::processPending), which is the shape an outbox
 * wants — read globally, act locally.
 *
 * Reports the remaining backlog rather than only what it managed, since a drain that clears fifty
 * rows out of five thousand looks identical to a healthy one if you only print the fifty.
 */
class ProcessFinancePostings extends Command
{
    protected $signature = 'finance:process-postings {--limit=500}';

    protected $description = 'Post any pending/failed Finance outbox rows to the ledger';

    public function handle(FinancePoster $poster): int
    {
        $done = $poster->processPending((int) $this->option('limit'));

        $stuck = FinPosting::whereIn('status', ['pending', 'failed'])->count();

        $this->components->twoColumnDetail('Postings processed', (string) $done);
        $this->components->twoColumnDetail('Still pending/failed', $stuck.($stuck ? ' — investigate' : ''));

        /*
         * A backlog that survives the drain is a real problem — every row in it is a financial
         * event the ledger has not heard about — but it is not this command failing, and exiting
         * non-zero here would page somebody every few minutes for a condition finance:parity
         * already reports once a day against the company it belongs to.
         */
        return self::SUCCESS;
    }
}
