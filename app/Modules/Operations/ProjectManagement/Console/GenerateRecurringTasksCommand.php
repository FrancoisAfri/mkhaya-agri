<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Services\RecurrenceService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Materialises recurring tasks up to each rule's horizon (PM-1122).
 *
 * Runs per company, in that company's tenant context: a rule read outside one would be written back
 * with a NULL company_id, and the tasks it generated would belong to nobody — visible to every
 * tenant under the RLS policy, which treats an unset session as "no restriction".
 *
 * Safe to run twice. Every occurrence is unique on (occurrence_of_id, occurrence_date), so an
 * overlapping run inserts nothing rather than doubling tomorrow's inspection list.
 */
class GenerateRecurringTasksCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:generate-recurring-tasks
                            {--company= : Only this company id}';

    protected $description = 'Generate the tasks due from each recurring-task rule';

    public function handle(TenantContext $tenants, RecurrenceService $recurrences): int
    {
        if ($only = $this->option('company')) {
            $result = $tenants->run((int) $only, fn () => $recurrences->generateDue());
            $this->info("Company {$only}: {$result['tasks']} task(s) from {$result['rules']} rule(s).");

            return self::SUCCESS;
        }

        $companies = 0;
        $rules = 0;
        $tasks = 0;

        $this->eachTenant($tenants, function () use ($recurrences, &$companies, &$rules, &$tasks) {
            $companies++;
            $result = $recurrences->generateDue();
            $rules += $result['rules'];
            $tasks += $result['tasks'];
        });

        $this->info("Generated {$tasks} task(s) from {$rules} rule(s) across {$companies} companies.");

        return self::SUCCESS;
    }
}
