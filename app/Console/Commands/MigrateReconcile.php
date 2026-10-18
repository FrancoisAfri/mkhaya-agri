<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles the migrations table with reality.
 *
 * When a database was created from a SQL import (or had its migrations table
 * truncated), `php artisan migrate` fails on the first create-table migration
 * because the table already exists — and that failure blocks every later
 * (genuinely new) migration from running.
 *
 * This command scans every pending migration; for each one that *creates* a
 * table that ALREADY EXISTS, it records the migration as run WITHOUT executing
 * it. Migrations whose tables do not exist are left pending so a subsequent
 * `migrate` creates them normally.
 *
 * Safe to run repeatedly: on a fully-migrated database it does nothing.
 *
 *   php artisan migrate:reconcile          # apply
 *   php artisan migrate:reconcile --pretend # report only
 */
class MigrateReconcile extends Command
{
    protected $signature   = 'migrate:reconcile {--pretend : List what would be reconciled without changing anything}';
    protected $description = 'Mark already-existing create-table migrations as run so migrate can proceed to new ones';

    public function handle(Migrator $migrator): int
    {
        $repo = $migrator->getRepository();
        if (! $repo->repositoryExists()) {
            $this->warn('Migrations table does not exist — running migrate:install.');
            if (! $this->option('pretend')) {
                $repo->createRepository();
            }
        }

        $paths = array_values(array_unique(array_merge(
            [database_path('migrations')],
            $migrator->paths(),
        )));

        $files   = $migrator->getMigrationFiles($paths);          // [name => path]
        $ran     = $repo->repositoryExists() ? $repo->getRan() : []; // recorded names
        $pending = array_diff(array_keys($files), $ran);

        $batch       = $repo->repositoryExists() ? $repo->getNextBatchNumber() : 1;
        $reconciled  = [];
        $stillPending = [];

        foreach ($pending as $name) {
            $tables = $this->createdTables($files[$name]);

            // A create-table migration whose table(s) already exist = already applied.
            if ($tables !== [] && $this->allExist($tables)) {
                $reconciled[$name] = $tables;
                if (! $this->option('pretend')) {
                    $repo->log($name, $batch);
                }
            } else {
                $stillPending[] = $name;
            }
        }

        $this->info(($this->option('pretend') ? '[pretend] ' : '') . 'Reconciled ' . count($reconciled) . ' already-applied migration(s):');
        foreach ($reconciled as $name => $tables) {
            $this->line("  ✓ {$name}  (" . implode(', ', $tables) . ')');
        }

        $this->info('Left pending (will run on next migrate): ' . count($stillPending));
        foreach (array_slice($stillPending, 0, 40) as $name) {
            $this->line("  • {$name}");
        }
        if (count($stillPending) > 40) {
            $this->line('  … and ' . (count($stillPending) - 40) . ' more');
        }

        return self::SUCCESS;
    }

    /** Tables a migration creates, parsed from Schema::create('table', …). */
    private function createdTables(string $path): array
    {
        $contents = (string) file_get_contents($path);
        preg_match_all('/Schema::create\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $contents, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    private function allExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }
        return true;
    }
}
