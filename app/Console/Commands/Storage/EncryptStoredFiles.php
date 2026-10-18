<?php

namespace App\Console\Commands\Storage;

use App\Core\Storage\DocumentEncryption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts files already sitting in `tenant_files`, and can put them back.
 *
 * New uploads are encrypted by the adapter from the moment the feature is switched on. Everything
 * stored before that stays plaintext and readable — which is correct, since a mixed table has to
 * work — but it also means the older documents, usually the ones that have been around long enough
 * to matter, are the ones left in the clear. This closes that gap.
 *
 * Dry by default. Nothing is written until --execute, which is the right default for a command whose
 * job is to rewrite every document in the platform.
 *
 *   php artisan storage:encrypt                      # report what would change
 *   php artisan storage:encrypt --execute            # encrypt plaintext rows
 *   php artisan storage:encrypt --verify             # prove every row still decrypts
 *   php artisan storage:encrypt --decrypt --execute  # put it all back
 *
 * Each row is verified before its old bytes are replaced: the new envelope is decrypted and checked
 * against the stored SHA-256 of the plaintext, and a row that does not match is skipped, not
 * written. That check is the difference between a migration and a shredder.
 */
class EncryptStoredFiles extends Command
{
    protected $signature = 'storage:encrypt
        {--execute : Actually write. Without this the command only reports.}
        {--decrypt : Reverse — return encrypted rows to plaintext.}
        {--verify : Read every row and confirm it decrypts to its recorded hash.}
        {--disk=* : Restrict to these disks.}
        {--limit=0 : Stop after this many rows.}';

    protected $description = 'Encrypt (or decrypt) file bytes already stored in the database.';

    public function handle(DocumentEncryption $cipher): int
    {
        // The orphan-safe context: no tenant session variable, so RLS does not hide rows belonging
        // to companies other than whichever one happens to be current. A backfill that only sees
        // one tenant's files is worse than none, because it reports success.
        DB::statement("SELECT set_config('app.company_id', '', false)");

        if ($this->option('verify')) {
            return $this->verify($cipher);
        }

        $decrypting = (bool) $this->option('decrypt');
        $execute = (bool) $this->option('execute');

        if (! $decrypting && ! $cipher->enabled()) {
            $this->error('Storage encryption is disabled or no key is configured — nothing to encrypt with.');
            $this->line('  Set STORAGE_ENCRYPTION_ENABLED=true and a key, then run again.');

            return self::FAILURE;
        }

        $rows = $this->targets($decrypting);
        $total = (clone $rows)->count();

        if ($total === 0) {
            $this->info($decrypting ? 'Nothing is encrypted.' : 'Every file is already encrypted.');

            return self::SUCCESS;
        }

        $verb = $decrypting ? 'Decrypting' : 'Encrypting';
        $execute
            ? $this->info("{$verb} {$total} file(s).")
            : $this->warn("Dry run — nothing is written. Would " . strtolower($verb) . " {$total} file(s).");

        $done = 0;
        $skipped = [];
        $bytesBefore = 0;
        $bytesAfter = 0;
        $limit = (int) $this->option('limit');

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);

        // Chunked by id rather than offset: the rows are being rewritten as we walk them, and an
        // offset-based page would skip rows as the set it is paging through changes underneath it.
        $lastId = 0;
        while (true) {
            $batch = (clone $rows)->where('id', '>', $lastId)->orderBy('id')->limit(25)->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $row) {
                $lastId = $row->id;

                try {
                    $stored = is_resource($row->contents) ? stream_get_contents($row->contents) : (string) $row->contents;
                    $plaintext = $cipher->decrypt($stored);

                    // The recorded hash is of the plaintext, so it is the one thing that stays true
                    // across both directions and is worth checking against.
                    if ($row->content_hash && hash('sha256', $plaintext) !== $row->content_hash) {
                        $skipped[] = [$row->id, $row->path, 'stored hash does not match its contents'];

                        continue;
                    }

                    $rewritten = $decrypting ? $plaintext : $cipher->encrypt($plaintext);

                    // Round-trip the new bytes before trusting them with the only copy.
                    if (! $decrypting && $cipher->decrypt($rewritten) !== $plaintext) {
                        $skipped[] = [$row->id, $row->path, 'the re-encrypted copy did not decrypt back'];

                        continue;
                    }

                    $bytesBefore += strlen($stored);
                    $bytesAfter += strlen($rewritten);

                    if ($execute) {
                        $this->writeBytes((int) $row->id, $rewritten, [
                            'encryption'   => $decrypting ? DocumentEncryption::METHOD_NONE : $cipher->method(),
                            'content_hash' => hash('sha256', $plaintext),
                        ]);
                    }

                    $done++;
                } catch (\Throwable $e) {
                    $skipped[] = [$row->id, $row->path, substr($e->getMessage(), 0, 70)];
                }

                $bar->advance();

                if ($limit > 0 && $done >= $limit) {
                    break 2;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(($execute ? 'Rewrote ' : 'Would rewrite ') . $done . ' file(s).');
        $this->line('  bytes stored: ' . number_format($bytesBefore) . ' → ' . number_format($bytesAfter));

        if ($skipped !== []) {
            $this->newLine();
            $this->line('<fg=red>Left untouched:</>');
            foreach (array_slice($skipped, 0, 20) as [$id, $path, $why]) {
                $this->line(sprintf('  #%-6d %-48s %s', $id, substr((string) $path, 0, 48), $why));
            }
            if (count($skipped) > 20) {
                $this->line('  … and ' . (count($skipped) - 20) . ' more');
            }
        }

        if ($execute && ! $decrypting) {
            $this->newLine();
            $this->line('<fg=yellow>Run VACUUM FULL tenant_files to release the space the old rows held.</>');
        }

        return $skipped === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Reads every row and confirms it decrypts to what was recorded. */
    private function verify(DocumentEncryption $cipher): int
    {
        $ok = 0;
        $bad = [];
        $noHash = 0;

        foreach (DB::table('tenant_files')->orderBy('id')->cursor() as $row) {
            try {
                $stored = is_resource($row->contents) ? stream_get_contents($row->contents) : (string) $row->contents;
                $plaintext = $cipher->decrypt($stored);

                if (! $row->content_hash) {
                    $noHash++;

                    continue;
                }

                hash('sha256', $plaintext) === $row->content_hash
                    ? $ok++
                    : $bad[] = [$row->id, $row->path, 'contents do not match the recorded hash'];
            } catch (\Throwable $e) {
                $bad[] = [$row->id, $row->path, substr($e->getMessage(), 0, 70)];
            }
        }

        $this->info("Verified {$ok} file(s).");
        if ($noHash > 0) {
            $this->line("  {$noHash} row(s) have no recorded hash to check against.");
        }

        foreach ($bad as [$id, $path, $why]) {
            $this->line(sprintf('  <fg=red>#%-6d %-48s %s</>', $id, substr((string) $path, 0, 48), $why));
        }

        return $bad === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Rows in the state we are moving away from. */
    private function targets(bool $decrypting)
    {
        return DB::table('tenant_files')
            ->when($decrypting,
                fn ($q) => $q->where('encryption', '!=', DocumentEncryption::METHOD_NONE),
                fn ($q) => $q->where('encryption', DocumentEncryption::METHOD_NONE))
            ->when((array) $this->option('disk'), fn ($q, $disks) => $q->whereIn('disk', $disks));
    }

    /**
     * Replaces one row's bytes.
     *
     * LOB-bound for the same reason the adapter does it: binding binary through the query builder
     * succeeds and silently stores something else.
     *
     * @param  array<string,string>  $columns
     */
    private function writeBytes(int $id, string $bytes, array $columns): void
    {
        $pdo = DB::connection()->getPdo();

        $assignments = implode(', ', array_map(fn ($c) => "\"{$c}\" = ?", array_keys($columns)));
        $statement = $pdo->prepare("update tenant_files set {$assignments}, contents = ? where id = ?");

        $position = 1;
        foreach ($columns as $value) {
            $statement->bindValue($position++, $value);
        }

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $bytes);
        rewind($handle);
        $statement->bindParam($position++, $handle, \PDO::PARAM_LOB);
        $statement->bindValue($position, $id, \PDO::PARAM_INT);

        try {
            $statement->execute();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
