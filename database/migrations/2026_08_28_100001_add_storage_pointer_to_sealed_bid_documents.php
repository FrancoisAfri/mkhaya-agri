<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A path column for sealed bid documents.
 *
 * This table was left out of the earlier sweeps on the grounds that its seal is procedural and
 * moving the bytes might need the sealing rules revisited. Reading BidSeal shows that was
 * over-cautious: a bid is sealed before it is stored and unsealed after it is read back, and its
 * SHA-256 digest is taken over the plaintext and kept outside the ciphertext. None of that depends
 * on where the ciphertext sits.
 *
 * What the move adds is the storage layer's own encryption over the top, so a sealed bid is
 * encrypted twice under two different keys — the tender's envelope key and the storage key. That
 * costs nothing and removes the last table writing files into a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('procgov_bid_submission_documents')
            || Schema::hasColumn('procgov_bid_submission_documents', 'storage_path')) {
            return;
        }

        Schema::table('procgov_bid_submission_documents', fn (Blueprint $t) => $t->string('storage_path', 512)->nullable());
    }

    public function down(): void
    {
        if (! Schema::hasTable('procgov_bid_submission_documents')
            || ! Schema::hasColumn('procgov_bid_submission_documents', 'storage_path')) {
            return;
        }

        $stranded = \Illuminate\Support\Facades\DB::table('procgov_bid_submission_documents')
            ->whereNotNull('storage_path')->count();

        if ($stranded > 0) {
            throw new RuntimeException(
                "Refusing to drop the pointer: {$stranded} sealed bid(s) are stored on the disk."
            );
        }

        Schema::table('procgov_bid_submission_documents', fn (Blueprint $t) => $t->dropColumn('storage_path'));
    }
};
