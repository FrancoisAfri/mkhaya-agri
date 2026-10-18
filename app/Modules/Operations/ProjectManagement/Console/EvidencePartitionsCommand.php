<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the evidence ledger's monthly partitions ahead of incoming evidence, and archives old ones
 * (PM-ADR-002, PM-1006).
 *
 * Two jobs, one command, because they are the same maintenance window:
 *
 *  - **ensure**: create partitions for the months ahead. There is a DEFAULT partition so nothing
 *    can ever fail to insert, but a month's evidence sitting in DEFAULT loses the pruning that
 *    makes the timeline query fast — and moving it out later means rewriting rows.
 *  - **archive**: detach partitions older than the retention window. Detaching is instant and
 *    touches no live row, which is the entire reason the table is partitioned. A detached
 *    partition is still a table: it can be dumped to cold storage and re-attached to answer an
 *    audit question, which is why this detaches rather than drops.
 */
class EvidencePartitionsCommand extends Command
{
    protected $signature = 'pm:evidence-partitions
        {--months=6 : How many months ahead to create}
        {--archive= : Detach partitions older than this many months}
        {--drop : Also drop detached partitions — irreversible, and only for a tenant that has said so}';

    protected $description = 'Create upcoming evidence partitions and archive old ones';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('The evidence ledger is partitioned only on PostgreSQL; nothing to do.');

            return self::SUCCESS;
        }

        $created = $this->ensureAhead((int) $this->option('months'));
        $this->info("Evidence partitions: {$created} created.");

        if ($archive = $this->option('archive')) {
            $detached = $this->archiveOlderThan((int) $archive, (bool) $this->option('drop'));
            $this->info("Archived {$detached} partition(s) older than {$archive} months.");
        }

        $this->reportDefaultPartition();

        return self::SUCCESS;
    }

    private function ensureAhead(int $months): int
    {
        $created = 0;

        for ($i = 0; $i <= $months; $i++) {
            $from = now()->startOfMonth()->addMonths($i);
            $to = $from->copy()->addMonth();
            $name = sprintf('pm_evidence_events_y%sm%s', $from->format('Y'), $from->format('m'));

            if ($this->partitionExists($name)) {
                continue;
            }

            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF pm_evidence_events FOR VALUES FROM ('%s') TO ('%s')",
                $name, $from->toDateString(), $to->toDateString()
            ));

            // A partition inherits the parent's policies for access *through* the parent, but a
            // direct query against the partition would not be filtered — so it gets its own.
            $this->applyTenantRls($name);
            $created++;
        }

        return $created;
    }

    /**
     * Detach partitions whose whole range is older than the retention window.
     *
     * Detached, not dropped, unless explicitly told otherwise: evidence is what a dispute is
     * argued from, and "we deleted it on a schedule" is not an answer anybody wants to give.
     */
    private function archiveOlderThan(int $months, bool $drop): int
    {
        $cutoff = now()->startOfMonth()->subMonths($months);
        $detached = 0;

        foreach ($this->partitions() as $name) {
            if (! preg_match('/y(\d{4})m(\d{2})$/', $name, $m)) {
                continue;   // the DEFAULT partition, or something hand-made
            }

            $partitionMonth = \Illuminate\Support\Carbon::create((int) $m[1], (int) $m[2], 1);

            if ($partitionMonth->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            DB::statement("ALTER TABLE pm_evidence_events DETACH PARTITION {$name}");
            $this->line("  detached {$name}");

            if ($drop) {
                DB::statement("DROP TABLE {$name}");
                $this->warn("  dropped {$name} — this cannot be undone");
            }

            $detached++;
        }

        return $detached;
    }

    /**
     * The DEFAULT partition should stay empty. Rows in it mean evidence arrived for a month with
     * no partition — nothing is lost, but the timeline query stops pruning, so it is worth saying
     * out loud rather than discovering through a slow page.
     */
    private function reportDefaultPartition(): void
    {
        $count = DB::table('pm_evidence_events_default')->count();

        if ($count > 0) {
            $this->warn("pm_evidence_events_default holds {$count} row(s): evidence arrived for a month with no partition.");
        }
    }

    private function partitionExists(string $name): bool
    {
        return DB::selectOne('SELECT to_regclass(?) AS t', [$name])?->t !== null;
    }

    /** @return list<string> */
    private function partitions(): array
    {
        $rows = DB::select("
            SELECT c.relname AS name
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            WHERE i.inhparent = 'pm_evidence_events'::regclass
        ");

        return array_map(fn ($r) => $r->name, $rows);
    }

    private function applyTenantRls(string $table): void
    {
        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
    }
}
