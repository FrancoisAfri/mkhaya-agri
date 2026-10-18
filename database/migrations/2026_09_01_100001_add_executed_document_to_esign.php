<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep the document people actually signed.
 *
 * `document_path` held the original and `signed_document_path` was already taken — by the
 * signature certificate, the audit-trail PDF that GenerateSignedDocumentJob writes. There was
 * nowhere for the executed copy: the original with the signatures burnt into it, which is the one
 * document of the three that anybody would take to a lawyer.
 *
 * `executed_hash` is the sha256 of those bytes. The signature is only worth as much as the proof
 * of what was signed, so the hash is recorded when the last signature lands and never recomputed —
 * a hash generated on demand proves the file matches itself, which is no proof at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esign_envelopes', function (Blueprint $table) {
            $table->string('executed_document_path')->nullable()->after('signed_document_path');
            $table->string('executed_hash', 64)->nullable()->after('executed_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('esign_envelopes', function (Blueprint $table) {
            $table->dropColumn(['executed_document_path', 'executed_hash']);
        });
    }
};
