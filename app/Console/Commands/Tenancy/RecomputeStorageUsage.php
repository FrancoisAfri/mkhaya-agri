<?php

namespace App\Console\Commands\Tenancy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every company's DATABASE data footprint — the on-disk size of all
 * their relational data across every tenant-scoped table (tables carrying a
 * company_id). Each table's real on-disk size (heap + indexes + TOAST, via
 * pg_total_relation_size) is allocated to companies in proportion to their row
 * share, then summed per company and cached in company_storage_stats. Combined
 * with live file bytes (tenant_files) this gives each tenant's true "everything
 * we store for them in our DB" usage. Runs nightly; Postgres only.
 */
class RecomputeStorageUsage extends Command
{
    protected $signature = 'storage:recompute {--min-rows=1 : Skip tables with fewer than N rows}';
    protected $description = 'Recompute each company\'s database data footprint for storage metering';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('Storage recompute requires PostgreSQL. Skipped.');

            return self::SUCCESS;
        }

        // Every tenant-scoped table (has a company_id column) in the public schema.
        $tables = DB::table('information_schema.columns')
            ->where('column_name', 'company_id')
            ->where('table_schema', 'public')
            ->pluck('table_name')
            ->unique()
            ->values();

        $this->info("Scanning {$tables->count()} tenant-scoped tables…");

        $dataBytes = [];   // company_id => bytes
        $scanned = 0;

        foreach ($tables as $table) {
            try {
                $size = (int) DB::selectOne('SELECT pg_total_relation_size(?) AS b', [$table])->b;
                if ($size === 0) {
                    continue;
                }

                // Row share per company (NULL company_id = platform/global → ignored).
                $rows = DB::table($table)
                    ->select('company_id', DB::raw('count(*) AS n'))
                    ->whereNotNull('company_id')
                    ->groupBy('company_id')
                    ->get();

                $total = (int) DB::table($table)->count();
                if ($total === 0) {
                    continue;
                }

                foreach ($rows as $r) {
                    if ((int) $r->n < (int) $this->option('min-rows')) {
                        continue;
                    }
                    $dataBytes[$r->company_id] = ($dataBytes[$r->company_id] ?? 0) + (int) round($size * $r->n / $total);
                }
                $scanned++;
            } catch (\Throwable $e) {
                // A missing/locked table shouldn't abort the whole sweep.
                $this->warn("  skipped {$table}: {$e->getMessage()}");
            }
        }

        $now = now();
        foreach ($dataBytes as $companyId => $bytes) {
            DB::table('company_storage_stats')->updateOrInsert(
                ['company_id' => $companyId],
                ['data_bytes' => $bytes, 'computed_at' => $now, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        // Zero-out companies that no longer have any data so stale numbers don't linger.
        if ($dataBytes !== []) {
            DB::table('company_storage_stats')
                ->whereNotIn('company_id', array_keys($dataBytes))
                ->update(['data_bytes' => 0, 'computed_at' => $now, 'updated_at' => $now]);
        }

        $this->info(sprintf('Recomputed data footprint for %d company(ies) across %d non-empty tables.', count($dataBytes), $scanned));

        return self::SUCCESS;
    }
}
