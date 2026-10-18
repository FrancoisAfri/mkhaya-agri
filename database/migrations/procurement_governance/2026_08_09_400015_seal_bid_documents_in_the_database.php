<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bid documents are stored in the database, sealed, like every other file on this platform.
 *
 * The previous shape carried a `stored_path` pointing at a private disk, which is not how this
 * platform stores anything: FileStorage puts the bytes in the row as base64, and every module
 * follows it. A single feature keeping its files somewhere else is how backups end up incomplete —
 * the database restores and the documents do not.
 *
 * Following that convention has a consequence worth stating, because it is the reason this column
 * is not a plain copy of the file. Once the bytes live in the same database as everything else,
 * "read the database" is the whole attack: a bid document routinely restates the price the seal is
 * protecting, so storing it in the clear beside a sealed amount would defeat the seal entirely. So
 * the bytes are encrypted under the tender's envelope key before they are encoded, and the digest
 * is taken over the *plaintext* so an altered document fails at opening exactly as an altered bid
 * amount does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procgov_bid_submission_documents', function (Blueprint $t) {
            $t->dropColumn('stored_path');

            // Base64 of the AES-256-GCM ciphertext. Long text rather than binary to match how the
            // rest of the platform stores files, so one download path serves everything.
            $t->longText('sealed_data')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('procgov_bid_submission_documents', function (Blueprint $t) {
            $t->dropColumn('sealed_data');
            $t->string('stored_path', 500)->nullable();
        });
    }
};
