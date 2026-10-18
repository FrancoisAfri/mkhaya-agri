<?php

namespace App\Console\Commands\Storage;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Reports whether the old blob columns can safely be dropped.
 *
 * Every file-owning table now keeps its bytes twice: in the column it always used, and on the
 * shared `documents` disk with a path recorded beside it. Reads prefer the path, so the duplication
 * costs storage and nothing else — and it is what makes the migration reversible.
 *
 * Dropping the columns is the step that ends that. It is also the only irreversible one, so this
 * exists to answer the question it depends on with evidence rather than confidence: for every row
 * that still holds bytes, is there a file on the disk that opens and matches?
 *
 * Read-only. It writes nothing, and deliberately has no --execute: pruning is `storage:consolidate
 * --prune --execute`, and it should be run per table, after reading this.
 *
 *   php artisan storage:readiness            # every table
 *   php artisan storage:readiness --table=contracts
 *   php artisan storage:readiness --deep     # open every file rather than sampling
 */
class StorageReadinessReport extends Command
{
    protected $signature = 'storage:readiness
        {--table=* : Restrict to these tables.}
        {--deep : Open every file instead of sampling 25 per table.}
        {--sample=25 : How many files to open per table when not running deep.}';

    protected $description = 'Report whether each table\'s legacy blob column can safely be dropped.';

    /**
     * Path column candidates, in the order they are looked for.
     *
     * More than one because these tables were written independently: the governance library called
     * it `file_path` before the convention settled, a contract row holds two files under their own
     * names, and several are named after what they hold.
     */
    private const PATH_COLUMNS = [
        'storage_path', 'file_path', 'pdf_path', 'template_path',
        'bank_file_path', 'disposal_document_path', 'evidence_file_path',
        'document_path', 'signed_document_path',
    ];

    /** Columns that look like a blob but are not a file. */
    private const NOT_FILES = [
        'notifications', 'tenant_files', 'sheq_file_chunks', 'procurement_insights',
        'jobs', 'failed_jobs', 'sessions',
    ];

    public function handle(): int
    {
        // No tenant context: a readiness report that only sees one company's rows is worse than
        // none, because it reports "safe" for data it never looked at.
        DB::statement("SELECT set_config('app.company_id', '', false)");

        $only = (array) $this->option('table');
        $sample = $this->option('deep') ? null : max(1, (int) $this->option('sample'));

        $rows = [];
        $blocked = [];

        foreach ($this->targets($only) as [$table, $blob, $path]) {
            $withBytes = DB::table($table)->whereNotNull($blob)->count();
            $withPath = DB::table($table)->whereNotNull($path)->count();

            // Rows holding bytes but pointing nowhere. Dropping the column loses these outright.
            $orphaned = DB::table($table)->whereNotNull($blob)->whereNull($path)->count();

            [$checked, $unreadable] = $this->verify($table, $blob, $path, $sample);

            $rows[] = [$table, $blob, $withBytes, $withPath, $orphaned, $checked, $unreadable];

            if ($orphaned > 0 || $unreadable > 0) {
                $blocked[] = [$table, $orphaned, $unreadable];
            }
        }

        $this->render($rows);

        $this->newLine();

        if ($blocked === []) {
            $this->info('Every row that holds bytes also has a readable file on the disk.');
            $this->line('  Pruning is safe: <fg=cyan>php artisan storage:consolidate --prune --execute --table=…</>');
            $this->line('  <fg=yellow>Then VACUUM FULL the table, or the space is not released.</>');

            return self::SUCCESS;
        }

        $this->line('<fg=red>Not ready — these would lose data if their column were dropped:</>');
        foreach ($blocked as [$table, $orphaned, $unreadable]) {
            $this->line(sprintf('  %-40s %d row(s) with no path, %d file(s) that would not open',
                $table, $orphaned, $unreadable));
        }

        return self::FAILURE;
    }

    /**
     * Opens files and checks they still contain something.
     *
     * Sampled by default. Opening every file means pulling each one through memory, and on a table
     * of scanned PDFs that is the slowest thing this command could do — `--deep` is there for the
     * run immediately before actually dropping a column, which is the moment it is worth the wait.
     *
     * @return array{0:int,1:int} checked and unreadable
     */
    private function verify(string $table, string $blob, string $path, ?int $sample): array
    {
        $query = DB::table($table)->whereNotNull($path)->select(['id', $path]);

        if ($sample !== null) {
            $query->inRandomOrder()->limit($sample);
        }

        $disk = Storage::disk('documents');
        $checked = 0;
        $unreadable = 0;

        foreach ($query->cursor() as $row) {
            $checked++;

            try {
                // exists() alone would pass for a zero-byte file, which is the shape a half-finished
                // write leaves behind and exactly what this is meant to catch.
                if (! $disk->exists($row->{$path}) || $disk->size($row->{$path}) === 0) {
                    $unreadable++;
                }
            } catch (\Throwable) {
                $unreadable++;
            }
        }

        return [$checked, $unreadable];
    }

    /** @param list<array{0:string,1:string,2:int,3:int,4:int,5:int,6:int}> $rows */
    private function render(array $rows): void
    {
        $this->line(sprintf('  %-38s %-22s %8s %8s %8s %8s', 'table', 'column', 'bytes', 'paths', 'orphans', 'bad'));
        $this->line('  ' . str_repeat('─', 96));

        foreach ($rows as [$table, $blob, $withBytes, $withPath, $orphaned, $checked, $unreadable]) {
            if ($withBytes === 0 && $withPath === 0) {
                continue; // nothing stored either way; nothing to decide
            }

            $flag = ($orphaned > 0 || $unreadable > 0) ? '<fg=red>' : '<fg=green>';

            $this->line(sprintf('  %s%-38s %-22s %8d %8d %8d %8d</>',
                $flag, $table, $blob, $withBytes, $withPath, $orphaned, $unreadable));
        }
    }

    /**
     * Tables holding a file, paired with the column that points at it.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function targets(array $only): array
    {
        $columns = DB::select("
            select table_name, column_name from information_schema.columns
            where table_schema = 'public'
              and data_type in ('text', 'bytea')
              and (column_name like '%file_data%' or column_name like '%_data'
                   or column_name in ('data', 'pdf_data', 'pdf', 'contents'))
            order by table_name, column_name
        ");

        $targets = [];

        foreach ($columns as $column) {
            $table = $column->table_name;

            if (in_array($table, self::NOT_FILES, true) || ($only !== [] && ! in_array($table, $only, true))) {
                continue;
            }

            $path = $this->pathColumnFor($table, $column->column_name);

            if ($path !== null) {
                $targets[] = [$table, $column->column_name, $path];
            }
        }

        return $targets;
    }

    /** The path column belonging to a given blob column, preferring the one named after it. */
    private function pathColumnFor(string $table, string $blob): ?string
    {
        // document_data → document_path, so a table holding two files pairs them correctly rather
        // than reporting both against whichever path column happened to be found first.
        $paired = preg_replace('/_?data$/', '', $blob) . '_path';

        foreach (array_merge([$paired], self::PATH_COLUMNS) as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
