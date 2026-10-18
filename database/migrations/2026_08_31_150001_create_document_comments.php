<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Talking about a document, next to the document.
 *
 * The conversation about a contract currently happens in email, and it is the half of the record
 * that never arrives: the document is filed and the reason it says what it says is in somebody's
 * inbox. Six months later the clause is queried and nobody can find the exchange that produced it.
 *
 * Two decisions worth stating. A comment can name a version, because "clause 4 is wrong" means
 * nothing once clause 4 has been rewritten — pinned to 1.2 it stays true. And a thread can be
 * resolved rather than deleted, so a document with forty comments on it can still show the three
 * that are still live without losing the record of the other thirty-seven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');
            // The version this is about, where it is about one. Null means the document generally.
            $table->unsignedBigInteger('version_id')->nullable();
            /*
             * One level of replies, not a tree. A thread in a side panel is read top to bottom, and
             * arbitrary nesting turns a disagreement about a clause into something nobody can follow.
             */
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('body');
            // Who was named in it, so being mentioned can be acted on without re-parsing the text
            // every time somebody asks what they have been pulled into.
            $table->json('mentions')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_id']);
            $table->index(['document_id', 'parent_id']);
            $table->index(['company_id', 'resolved_at']);
        });

        /*
         * Tenancy in the database rather than only in the query builder. A comment quotes the
         * document — often the sensitive part of it, which is what people comment on — so a listing
         * that forgot to scope would leak the contents of documents the reader cannot open.
         */
        DB::statement("ALTER TABLE document_comments ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_comments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_comments FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_comments_tenant ON document_comments USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_comments');
    }
};
