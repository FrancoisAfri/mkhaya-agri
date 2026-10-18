<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Services\ArchivedCompanyPurge;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes the business data of companies converted to resellers once their retention period is
 * over. Each company is purged in its own transaction; one that fails is reported and retried the
 * next night without holding up the rest.
 */
class PurgeArchivedCompanies extends Command
{
    protected $signature = 'tenancy:purge-archived {--dry-run : List what is due and how many rows each holds, without deleting}';

    protected $description = 'Delete the data of archived (converted-to-reseller) companies whose retention period has ended';

    public function handle(ArchivedCompanyPurge $purge): int
    {
        $due = $purge->due();
        if (! $due) {
            $this->info('No archived company is due for purging.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($due as $company) {
            if ($this->option('dry-run')) {
                $rows = $purge->inventory($company);
                $this->line("{$company->id} {$company->name}: due since {$company->purge_after->toDateString()}, " . array_sum($rows) . ' rows in ' . count($rows) . ' tables');

                continue;
            }
            try {
                $deleted = $purge->purge($company);
                $this->info("{$company->id} {$company->name}: deleted " . array_sum($deleted) . ' rows from ' . count($deleted) . ' tables.');
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$company->id} {$company->name}: not purged ({$e->getMessage()}).");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
