<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checking a document out before working on it.
 *
 * Versioning already meant no edit could destroy an earlier one, but it never stopped two people
 * working on the same document at once — and it cannot, because the loss happens before either of
 * them uploads. Two people download the contract on Monday, both edit their copy, and the second
 * upload silently buries the first person's afternoon in the version history where nobody looks.
 *
 * A lock is not a permission. It is one person saying "I am working on this", and it has to expire
 * on its own: somebody who checks a document out and then goes on leave must not freeze it until
 * they come back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('locked_by')->nullable()->after('updated_by');
            $table->timestamp('locked_at')->nullable()->after('locked_by');
            $table->timestamp('lock_expires_at')->nullable()->after('locked_at');
            $table->string('lock_note', 255)->nullable()->after('lock_expires_at');

            // Answering "what is checked out, and by whom" without reading every document.
            $table->index(['company_id', 'locked_by'], 'documents_company_locked_by_index');
        });

        Schema::table('document_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('lock_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_company_locked_by_index');
            $table->dropColumn(['locked_by', 'locked_at', 'lock_expires_at', 'lock_note']);
        });

        Schema::table('document_settings', fn (Blueprint $table) => $table->dropColumn('lock_hours'));
    }
};
