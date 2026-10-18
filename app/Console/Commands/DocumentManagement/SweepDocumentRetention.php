<?php

namespace App\Console\Commands\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Services\RetentionService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * The retention sweep: work out what has come due, and tell somebody.
 *
 * It destroys nothing. Every run ends with documents sitting in a queue waiting for a person, which
 * is the only version of this that an organisation can defend afterwards — "the schedule said five
 * years and Thandi approved the destruction on this date" is a policy being followed; "the cron
 * deleted it" is a story about a system nobody was supervising.
 *
 * Runs per tenant for the same reason the expiry sweep does: row-level security only engages once
 * the session knows which company it is acting for, and a sweep without one would apply one
 * organisation's retention schedule to another's documents.
 *
 *   php artisan documents:retention-sweep
 *   php artisan documents:retention-sweep --company=1
 *   php artisan documents:retention-sweep --dry-run
 */
class SweepDocumentRetention extends Command
{
    use RunsPerTenant;

    protected $signature = 'documents:retention-sweep
        {--company= : Only this company.}
        {--dry-run : Report what is scheduled without marking anything due.}';

    protected $description = 'Recompute document disposal dates and queue what has come due for review.';

    public function handle(TenantContext $tenants, RetentionService $retention): int
    {
        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing is marked due.');

            $this->eachTenant($tenants, function () use ($retention) {
                $summary = $retention->summary();

                $this->line(sprintf(
                    '  scheduled %d · awaiting review %d · approved for disposal %d · under hold %d',
                    $summary['scheduled'], $summary['due'], $summary['approved'], $summary['held'],
                ));
            });

            return self::SUCCESS;
        }

        $totals = ['scanned' => 0, 'dated' => 0, 'became_due' => 0, 'held' => 0];
        $automatic = ['archived' => 0, 'deleted' => 0, 'waiting' => 0, 'skipped_held' => 0];

        $this->eachTenant($tenants, function () use ($retention, &$totals, &$automatic) {
            $result = $retention->sweep();

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            /*
             * The automatic half, in this order deliberately: archive first, then delete. A document
             * that qualifies for both should be archived on its way out rather than deleted from a
             * listing it was never removed from, so its history reads the way it happened.
             */
            $archived = $retention->autoArchive();
            $automatic['archived'] += $archived['archived'];
            $automatic['skipped_held'] += $archived['held'];

            $deleted = $retention->autoDelete();
            $automatic['deleted'] += $deleted['deleted'];
            $automatic['waiting'] += $deleted['waiting'];
            $automatic['skipped_held'] += $deleted['held'];
        });

        $this->info(sprintf(
            'Scanned %d document(s); %d disposal date(s) updated; %d came due for review; %d left alone under legal hold.',
            $totals['scanned'], $totals['dated'], $totals['became_due'], $totals['held'],
        ));

        $this->info(sprintf(
            'Automatically archived %d; deleted %d (recoverable); %d waiting out the grace period; %d skipped under hold.',
            $automatic['archived'], $automatic['deleted'], $automatic['waiting'], $automatic['skipped_held'],
        ));

        return self::SUCCESS;
    }
}
