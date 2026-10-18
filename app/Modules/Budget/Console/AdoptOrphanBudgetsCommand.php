<?php

namespace App\Modules\Budget\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Find budget rows that belong to nobody, and hand them to a company on purpose.
 *
 * How they happen: a platform admin who has not switched into a tenant has no CurrentCompany, and
 * before the strict stamping went in the trait simply left `company_id` null. Row level security
 * matches `company_id = <session>`, so the row is invisible to every tenant from the moment it is
 * saved — including to the person who created it, as soon as they land in a company. The save looks
 * like it worked. Nothing warns anybody.
 *
 * Deliberately a command rather than a migration. Which tenant an orphaned budget belongs to is a
 * business question, not a schema one — guessing it in a migration is how one company's allocation
 * turns up in another company's board pack. So: report by default, adopt only when somebody names
 * the company.
 */
class AdoptOrphanBudgetsCommand extends Command
{
    protected $signature = 'budgets:adopt-orphans
                            {--company= : Company id to adopt the orphans into}
                            {--dry-run : Show what would be adopted and change nothing}';

    protected $description = 'Report budget rows with no company, and optionally adopt them into one.';

    /**
     * Every budget-module table, child tables last on report and first on adopt.
     *
     * Order matters when adopting: a child row adopted before its parent would briefly point at a
     * budget it cannot see, and the partial unique indexes are per company.
     */
    private const TABLES = [
        'financial_years', 'budget_periods', 'budget_categories',
        'budgets', 'budget_allocations', 'budget_owners', 'budget_approvals',
        'budget_adjustments', 'budget_overrides',
        'budget_reservations', 'budget_commitments', 'budget_consumptions',
        'budget_approval_flows', 'budget_approval_steps', 'budget_approval_requests', 'budget_approval_actions',
        'budget_alert_rules', 'budget_alerts', 'budget_phasings', 'budget_revisions',
    ];

    public function handle(): int
    {
        $counts = $this->orphanCounts();
        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('No orphaned budget rows.');

            return self::SUCCESS;
        }

        $this->table(['Table', 'Rows with no company'], collect($counts)
            ->filter()
            ->map(fn ($n, $table) => [$table, $n])
            ->values()
            ->all());

        $companyId = $this->option('company');

        if (! $companyId) {
            $this->warn("{$total} row(s) belong to no company and are invisible to every tenant.");
            $this->line('Re-run with --company=<id> to adopt them. Companies:');

            foreach (DB::table('companies')->get(['id', 'name']) as $company) {
                $this->line("  {$company->id}  {$company->name}");
            }

            return self::SUCCESS;
        }

        if (! DB::table('companies')->where('id', $companyId)->exists()) {
            $this->error("No company with id {$companyId}.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run — {$total} row(s) would be adopted into company {$companyId}.");

            return self::SUCCESS;
        }

        $adopted = $this->adopt((int) $companyId, $counts);

        $this->info("{$adopted} row(s) adopted into company {$companyId}.");

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function orphanCounts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $counts[$table] = (int) DB::table($table)->whereNull('company_id')->count();
        }

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    private function adopt(int $companyId, array $counts): int
    {
        $adopted = 0;

        DB::transaction(function () use ($companyId, $counts, &$adopted) {
            foreach (self::TABLES as $table) {
                if (($counts[$table] ?? 0) === 0) {
                    continue;
                }

                // Written straight through the query builder: these rows are invisible to Eloquent's
                // company scope by definition, so a model-based update would find none of them.
                $adopted += DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);

                $this->line("  {$table}: {$counts[$table]}");
            }
        });

        return $adopted;
    }
}
