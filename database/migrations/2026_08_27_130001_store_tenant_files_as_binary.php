<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores file bytes as raw binary instead of base64 text, and makes room for encryption.
 *
 * `contents` was a text column holding base64. Base64 costs roughly 33% on every byte, so a 10 MB
 * PDF occupied about 13.3 MB of database — paid for on disk, in every backup, and in every replica.
 * `bytea` holds the bytes themselves.
 *
 * The conversion runs inside Postgres via decode(). Doing it in PHP would pull every file through a
 * 128 MB process one row at a time, and the largest files are exactly the ones that would fail.
 *
 * Two columns join it, both of which the read path depends on:
 *
 *   encryption   — which envelope the bytes are in. Existing rows are honestly marked 'none'
 *                  rather than assumed, so a plain file is never mistaken for a failed decrypt.
 *   content_hash — SHA-256 of the plaintext. Lets a restore be verified against what was stored,
 *                  and is what a later dedupe pass would match on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_files')) {
            return;
        }

        if (! Schema::hasColumn('tenant_files', 'encryption')) {
            Schema::table('tenant_files', function ($table) {
                // Every existing row is plaintext, and defaulting to that is the safe direction:
                // a mislabelled plain file is unreadable, a mislabelled encrypted one is not.
                $table->string('encryption', 32)->default('none');
                $table->string('content_hash', 64)->nullable();
            });
        }

        // Already binary — this migration has run, or the table was created after it.
        if ($this->contentsType() === 'bytea') {
            return;
        }

        // Written as one statement per step rather than a transaction-wrapped block: on a large
        // table each of these is slow, and a partial run is recoverable because the migration
        // re-checks the column type on the way in.
        DB::statement('ALTER TABLE tenant_files ADD COLUMN contents_bin bytea');

        // decode() reverses base64 in the database. A row whose text is not valid base64 would
        // fail here, which is the correct outcome — it would mean the bytes were already wrong.
        DB::statement("UPDATE tenant_files SET contents_bin = decode(contents, 'base64')");

        DB::statement("UPDATE tenant_files SET content_hash = encode(sha256(contents_bin), 'hex') WHERE content_hash IS NULL");

        DB::statement('ALTER TABLE tenant_files DROP COLUMN contents');
        DB::statement('ALTER TABLE tenant_files RENAME COLUMN contents_bin TO contents');
        DB::statement("ALTER TABLE tenant_files ALTER COLUMN contents SET NOT NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_files') || $this->contentsType() !== 'bytea') {
            return;
        }

        /*
         * Reversing this re-encodes to base64, which only produces the original text for rows that
         * are not encrypted. Encrypted bytes would come back as base64 of ciphertext — still
         * intact, still decryptable by the application, but no longer what the old code expected.
         * Rolling back is therefore a job for `storage:encrypt --decrypt` first, and the guard
         * below refuses rather than quietly producing a table the old adapter cannot read.
         */
        $encrypted = DB::table('tenant_files')->where('encryption', '!=', 'none')->count();

        if ($encrypted > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$encrypted} file(s) are encrypted and the base64 column "
                . 'cannot represent them for the previous adapter. Run `php artisan storage:encrypt '
                . '--decrypt` first, then roll back.'
            );
        }

        DB::statement('ALTER TABLE tenant_files ADD COLUMN contents_txt text');
        DB::statement("UPDATE tenant_files SET contents_txt = encode(contents, 'base64')");
        DB::statement('ALTER TABLE tenant_files DROP COLUMN contents');
        DB::statement('ALTER TABLE tenant_files RENAME COLUMN contents_txt TO contents');
        DB::statement('ALTER TABLE tenant_files ALTER COLUMN contents SET NOT NULL');

        Schema::table('tenant_files', function ($table) {
            $table->dropColumn(['encryption', 'content_hash']);
        });
    }

    private function contentsType(): ?string
    {
        $row = DB::selectOne("
            select data_type from information_schema.columns
            where table_name = 'tenant_files' and column_name = 'contents'
        ");

        return $row->data_type ?? null;
    }
};
