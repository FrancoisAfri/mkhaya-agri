<?php

namespace App\Console\Commands\Tenancy;

use App\Modules\Tenancy\Support\SoleCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Give the rows that belong to nobody back to the company that made them.
 *
 * A tenant-scoped row saved with a null company_id is not a small inconsistency. Row-level
 * security matches `company_id = <session>`, and null matches nothing, so the row is invisible to
 * every tenant including the one that created it — while the save reported success. On the
 * installation this was written for, an entire content calendar was in that state: forty planned
 * topics on screen, and a scheduled runner that swept the tenant every five minutes and correctly
 * found nothing owed, because from inside the tenant there was nothing there.
 *
 * The write path that produced them is fixed in BelongsToCompany. This is the other half: the rows
 * already written are still invisible, and no amount of correct behaviour from here on reaches
 * back for them.
 *
 * ── When this refuses ────────────────────────────────────────────────────────
 *
 * Only where the owner is not a guess. A single-tenant installation has exactly one company, so
 * there is one possible answer. A multi-tenant installation does not, and assigning one customer's
 * orphans to another is a worse outcome than leaving them lost — so there this reports what it
 * found and changes nothing.
 */
class AdoptOrphanedRows extends Command
{
    protected $signature = 'tenancy:adopt-orphans
        {--dry-run : List what would be adopted and change nothing}
        {--module= : Restrict to one module directory, e.g. ContentMarketing}';

    protected $description = 'Assign tenant-scoped rows that were saved with no company to their owner';

    public function handle(): int
    {
        $companyId = SoleCompany::id();
        $dryRun = (bool) $this->option('dry-run');

        if ($companyId === null && ! $dryRun) {
            $this->error('Cannot determine an owner for orphaned rows on this installation.');
            $this->line('');
            $this->line('  This is safe only where there is exactly one possible answer: a self-hosted');
            $this->line('  installation with a single company. Re-run with --dry-run to see what is');
            $this->line('  orphaned without changing anything.');

            return self::FAILURE;
        }

        $tables = $this->scopedTables();

        if ($tables === []) {
            $this->warn('No tenant-scoped models found.');

            return self::SUCCESS;
        }

        $found = 0;
        $adopted = 0;

        foreach ($tables as $table => $column) {
            $count = DB::table($table)->whereNull($column)->count();

            if ($count === 0) {
                continue;
            }

            $found += $count;

            if ($dryRun || $companyId === null) {
                $this->line(sprintf('  %-44s %6d orphaned', $table, $count));

                continue;
            }

            DB::table($table)->whereNull($column)->update([$column => $companyId]);
            $adopted += $count;
            $this->line(sprintf('  %-44s %6d adopted by company #%d', $table, $count, $companyId));
        }

        $this->line('');

        if ($found === 0) {
            $this->info('No orphaned rows — every tenant-scoped record has an owner.');

            return self::SUCCESS;
        }

        if ($dryRun || $companyId === null) {
            $this->warn(sprintf('%d row(s) belong to no company and are invisible to every tenant.', $found));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d row(s) adopted by company #%d.', $adopted, $companyId));

        return self::SUCCESS;
    }

    /**
     * Every table where a null company_id is unreachable, and therefore a defect.
     *
     * Asked of the database rather than of the models, for two reasons. The weaker one is that
     * loading every model class to reflect on it means every model class must load, and two in
     * this codebase currently cannot — an unrelated broken import should not be able to take down
     * a repair tool.
     *
     * The stronger one is that the database is the authority on the question actually being asked.
     * Row-level security is what makes an unowned row invisible, so its policy is the definition
     * of whether null is a defect or a design. Nearly every policy here reads
     * `company_id = <session>`, under which a null row can never be selected by anyone. A handful
     * add `OR company_id IS NULL`, and on those tables the null rows are a shared library that
     * every tenant is meant to read — adopting those would hand a system-wide resource to one
     * company and take it from the rest.
     *
     * Reading the policy keeps the two in step by construction: a table that changes its mind
     * about null changes this command's behaviour in the same commit.
     *
     * @return array<string, string>
     */
    private function scopedTables(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->warn('Orphan detection reads row-level security policies and needs PostgreSQL.');

            return [];
        }

        $module = $this->option('module');
        $prefixes = $module ? $this->tablePrefixesFor($module) : null;

        // Aggregated per table, not per policy. A table may carry more than one, and two here
        // carry one policy that permits a null company_id and another that does not — between them
        // they hold 853 rows of shared onboarding templates. Examined a policy at a time, whether
        // those rows were adopted would come down to which policy the query returned last, and
        // adopting them would hand a shared library to one company and take it from the rest.
        // One permissive policy is enough to make the null deliberate.
        $rows = DB::select(
            "select c.table_name,
                    count(p.qual) as policy_count,
                    bool_or(p.qual like '%company_id IS NULL%') as any_policy_allows_null
               from information_schema.columns c
               left join pg_policies p
                 on p.tablename = c.table_name
                and p.schemaname = c.table_schema
              where c.table_schema = current_schema()
                and c.column_name = 'company_id'
           group by c.table_name"
        );

        $tables = [];

        foreach ($rows as $row) {
            $table = $row->table_name;

            // No policy at all means row-level security is not filtering this table, so a null
            // company_id is not hidden and this command has no business rewriting it.
            if ((int) $row->policy_count === 0) {
                continue;
            }

            if ($row->any_policy_allows_null) {
                continue; // null means "shared with every tenant" here
            }

            if ($prefixes !== null && ! Str::startsWith($table, $prefixes)) {
                continue;
            }

            $tables[$table] = 'company_id';
        }

        ksort($tables);

        return $tables;
    }

    /**
     * The table-name prefixes a module owns, read from its own models.
     *
     * Only reached when --module narrows the run, so a module holding an unloadable class costs
     * that module's filter rather than the whole command.
     *
     * @return list<string>
     */
    private function tablePrefixesFor(string $module): array
    {
        $dir = app_path('Modules/' . $module . '/Models');

        if (! is_dir($dir)) {
            return [];
        }

        $prefixes = [];

        foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
            $source = (string) file_get_contents($file->getRealPath());

            if (preg_match('/protected \$table\s*=\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $m)) {
                $prefixes[] = $m[1];

                continue;
            }

            $prefixes[] = Str::snake(Str::pluralStudly($file->getBasename('.php')));
        }

        return array_values(array_unique($prefixes));
    }
}
