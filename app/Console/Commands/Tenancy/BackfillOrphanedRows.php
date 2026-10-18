<?php

namespace App\Console\Commands\Tenancy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives an owner to rows that were written before row-level security went in.
 *
 * A row with a null `company_id` is invisible to every tenanted user, because the policy reads
 * `session IS NULL OR company_id = session` and a null column matches no session. It stays readable
 * only to an account that itself has no company — which is why this data can look perfectly fine to
 * whoever created it and be gone the moment their account is assigned to a company.
 *
 * The distinction that governs everything here: **a null company is sometimes the intent.** Shipped
 * contract templates, public holidays and platform settings are deliberately shared across tenants,
 * and stamping a company onto them takes a system-wide library and gives it to one customer. Those
 * tables are excluded by name, and the exclusion list is the most important thing in this file.
 *
 *   php artisan tenancy:backfill-orphans --company=1              # dry run
 *   php artisan tenancy:backfill-orphans --company=1 --execute
 *   php artisan tenancy:backfill-orphans --company=1 --table=tenders --execute
 */
class BackfillOrphanedRows extends Command
{
    protected $signature = 'tenancy:backfill-orphans
        {--company= : The company id to assign orphaned rows to.}
        {--execute : Actually write. Without this the command only reports.}
        {--table=* : Restrict to these tables.}
        {--include-logs : Also backfill audit and log tables, which are usually left alone.}';

    protected $description = 'Assign an owning company to rows left with a null company_id.';

    /**
     * Tables where a null company means "shared with every tenant".
     *
     * Backfilling any of these is worse than leaving them alone: the shipped contract templates
     * become one customer's private set, the public holidays stop applying to everybody else, and
     * the platform defaults stop being defaults. A model declaring `sharesGlobalRecords()` is
     * saying exactly this, and the tables below are the ones that do.
     */
    private const GLOBAL_BY_DESIGN = [
        'contracts_templates',
        'file_specifications', 'file_specification_fields',
        'public_holidays',
        'app_settings',
        'bargaining_councils', 'bargaining_council_rates',
        'sa_tax_years',
        'module_how_tos',
        'billing_modules', 'billing_plans', 'billing_addons', 'service_offerings',
        'security_modules', 'security_categories', 'security_module_ribbons',
    ];

    /**
     * Logs and audit trails.
     *
     * Left alone by default. A log line records what happened, and stamping a company onto one
     * after the fact asserts something about the past that nobody verified — an activity row whose
     * subject belonged to another tenant would now claim otherwise. `--include-logs` is there for
     * an installation that would rather have them scoped than invisible, which is a real position,
     * just not the safe default.
     */
    private const LOG_TABLES = [
        'activity_log', 'ai_usage_log', 'audit_ai_calls', 'pm_audit_log',
        'notification_logs', 'notifications', 'ai_chat_messages',
        'sheq_audit_log', 'document_events', 'payroll_audit_log',
    ];

    /**
     * Tables whose rows cannot be given an owner without forging them.
     *
     * The evidence ledger is append-only and hash-chained: each event's SHA-256 covers its own
     * fields — `company_id` among them, second in the canonical order — plus the hash of the event
     * before it. Writing a company onto an existing event changes what it hashes to, so the event
     * no longer matches its recorded hash and every event after it no longer matches its
     * predecessor. Verification then fails across the whole chain.
     *
     * That failure is the ledger working correctly. It is designed to notice exactly this, and a
     * backfill is indistinguishable from tampering because it *is* tampering, however well meant.
     *
     * Orphaned events are therefore left where they are: invisible to tenanted users, intact, and
     * still verifiable as their own chain. `--include-logs` does not override this, which is the
     * point — it is not a log, and there is no flag that makes rewriting it correct.
     */
    private const IMMUTABLE = [
        'pm_evidence_events',
    ];

    public function handle(): int
    {
        $companyId = (int) $this->option('company');

        if ($companyId <= 0 || ! DB::table('companies')->where('id', $companyId)->exists()) {
            $this->error('Give me a real --company= id. Companies: '
                . DB::table('companies')->pluck('name', 'id')->map(fn ($n, $i) => "{$i}={$n}")->implode(', '));

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $company = DB::table('companies')->where('id', $companyId)->value('name');

        // No tenant context: the orphans are precisely the rows a tenant context hides.
        DB::statement("SELECT set_config('app.company_id', '', false)");

        if (! $execute) {
            $this->warn("Dry run. Nothing is written. Would assign to company {$companyId} ({$company}).");
        } else {
            $this->info("Assigning orphaned rows to company {$companyId} ({$company}).");
        }

        $tables = $this->targets();
        $assigned = 0;
        $rows = [];
        $collisions = [];

        foreach ($tables as $table) {
            $count = $this->query($table)->count();
            if ($count === 0) {
                continue;
            }

            if ($execute) {
                try {
                    // Straight update rather than the model layer: these rows are invisible to the
                    // global scope by definition, so Eloquent would not find them to update.
                    $this->query($table)->update(['company_id' => $companyId]);
                } catch (\Throwable $e) {
                    /*
                     * Almost always a per-tenant unique key: the orphan carries a code the target
                     * company already uses, so giving it an owner would create two rows claiming
                     * the same thing.
                     *
                     * Reported and skipped rather than forced. Which of the two is the real one is
                     * a question about the business, not about the schema, and answering it by
                     * dropping whichever loses a race is how the wrong budget survives.
                     */
                    $collisions[] = [$table, $count, $this->constraintFrom($e->getMessage())];

                    continue;
                }
            }

            $rows[] = [$table, $count];
            $assigned += $count;
        }

        usort($rows, fn ($a, $b) => $b[1] <=> $a[1]);
        foreach (array_slice($rows, 0, 30) as $row) {
            $this->line(sprintf('  %-46s %6d', $row[0], $row[1]));
        }
        if (count($rows) > 30) {
            $this->line('  … and ' . (count($rows) - 30) . ' more table(s)');
        }

        $this->newLine();
        $this->info(($execute ? 'Assigned ' : 'Would assign ') . $assigned . ' row(s) across ' . count($rows) . ' table(s).');

        if ($collisions !== []) {
            $this->newLine();
            $this->line('<fg=red>Could not assign — the target company already has a row with the same key:</>');
            foreach ($collisions as [$table, $count, $constraint]) {
                $this->line(sprintf('  %-40s %5d  %s', $table, $count, $constraint));
            }
            $this->line('  <fg=yellow>These need a decision: keep the orphan, keep the existing row, or rename one.</>');
        }

        $skipped = $this->skippedSummary();
        if ($skipped !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Left alone deliberately:</>');
            foreach ($skipped as $line) {
                $this->line('  ' . $line);
            }
        }

        return self::SUCCESS;
    }

    /** The constraint name out of a driver message, which is the only useful part of it. */
    private function constraintFrom(string $message): string
    {
        return preg_match('/constraint "([^"]+)"/', $message, $m)
            ? $m[1]
            : \Illuminate\Support\Str::limit($message, 60);
    }

    /**
     * The orphaned rows in one table, minus any that should stay ownerless.
     *
     * A platform administrator has no company on purpose: it is what gives them visibility across
     * tenants, since the row-level policy passes when no company is set on the session. Stamping
     * one onto them would quietly confine the people whose job is to see everything — and it would
     * be found weeks later, by somebody unable to open a tenant they are supposed to support.
     */
    private function query(string $table)
    {
        $query = DB::table($table)->whereNull('company_id');

        if ($table === 'users') {
            $query->where(fn ($q) => $q->whereNull('is_platform_admin')->orWhere('is_platform_admin', false))
                ->where(fn ($q) => $q->whereNull('is_master_admin')->orWhere('is_master_admin', false));
        }

        return $query;
    }

    /** @return list<string> */
    private function targets(): array
    {
        $only = (array) $this->option('table');

        $tables = collect(DB::select("
            select table_name from information_schema.columns
            where table_schema = 'public' and column_name = 'company_id'
            order by table_name
        "))->pluck('table_name')->all();

        $excluded = array_merge(self::GLOBAL_BY_DESIGN, self::IMMUTABLE);
        if (! $this->option('include-logs')) {
            $excluded = array_merge($excluded, self::LOG_TABLES);
        }

        // Partitions inherit their parent's rules: a partition of an immutable table is the same
        // rows under another name, and backfilling it breaks the same chain.
        $excluded = array_merge($excluded, array_values(array_filter(
            $tables,
            fn ($t) => (bool) array_filter(self::IMMUTABLE, fn ($i) => str_starts_with($t, $i . '_')),
        )));

        return array_values(array_filter(
            $tables,
            fn ($t) => ! in_array($t, $excluded, true)
                && ($only === [] || in_array($t, $only, true))
                && Schema::hasTable($t),
        ));
    }

    /** @return list<string> */
    private function skippedSummary(): array
    {
        $lines = [];

        foreach (self::IMMUTABLE as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $n = DB::table($table)->whereNull('company_id')->count();
            if ($n > 0) {
                $lines[] = sprintf('%-44s %5d  hash-chained — assigning an owner would break it', $table, $n);
            }
        }

        foreach (self::GLOBAL_BY_DESIGN as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $n = DB::table($table)->whereNull('company_id')->count();
            if ($n > 0) {
                $lines[] = sprintf('%-44s %5d  shared with every tenant', $table, $n);
            }
        }

        if (! $this->option('include-logs')) {
            foreach (self::LOG_TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }
                $n = DB::table($table)->whereNull('company_id')->count();
                if ($n > 0) {
                    $lines[] = sprintf('%-44s %5d  log — use --include-logs', $table, $n);
                }
            }
        }

        return $lines;
    }
}
