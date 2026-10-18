<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One document, filed in more than one place.
 *
 * A document belongs in one folder and is looked for in several. The signed lease lives under
 * Property and the finance team looks for it under Contracts; the safety policy lives under SHEQ
 * and every induction pack points at it. Today the answer is to upload it twice, and two copies of
 * one document is the oldest failure a repository has: one of them gets a new version and the other
 * quietly becomes wrong, and nobody can tell which they are reading.
 *
 * A shortcut is a second place to find the same document, not a second document. It carries no
 * permissions of its own — the document's own rules still decide, so putting a shortcut in an open
 * folder cannot be a way of publishing something confidential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_shortcuts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('folder_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // The same document twice in one folder is one shortcut, not two.
            $table->unique(['document_id', 'folder_id']);
            $table->index(['company_id', 'folder_id']);
        });

        DB::statement("ALTER TABLE document_shortcuts ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_shortcuts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_shortcuts FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_shortcuts_tenant ON document_shortcuts USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_shortcuts');
    }
};
