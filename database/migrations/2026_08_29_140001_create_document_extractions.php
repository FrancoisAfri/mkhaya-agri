<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The text inside a document, pulled out once and kept.
 *
 * Two things depend on this and both are ruined by the same failure. Searching finds documents by
 * what they say rather than only what somebody typed in the title; asking a question about a
 * document needs the document's words to answer from. If extraction quietly yields nothing, search
 * reports "no results" for a document that plainly contains the phrase, and the assistant answers
 * confidently from an empty page.
 *
 * So the outcome is recorded as a status rather than as the presence or absence of text. "This is a
 * scan and there is no text layer" and "this file type is not supported" and "there is genuinely no
 * text in it" are three different answers, and a system that collapses them into an empty string
 * cannot tell anybody which one happened.
 *
 * Extraction belongs to a version, not to a document. Replacing the file is what makes the old text
 * wrong, and keying it to the document would leave the previous file's words answering questions
 * about the new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('version_id');

            // pending | extracted | empty | no_text_layer | unsupported | ocr_unavailable | failed
            $table->string('status', 24)->default('pending');

            // How it was read: pdftotext, docx, spreadsheet, plain, ocr. Kept because "the text
            // looks wrong" is a different investigation depending on which one produced it.
            $table->string('method', 24)->nullable();

            $table->longText('text')->nullable();
            $table->unsignedInteger('char_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('page_count')->nullable();

            // The tool's own words when it failed, for the person who has to work out why.
            $table->text('error')->nullable();
            $table->timestamp('extracted_at')->nullable();

            $table->timestamps();

            // One extraction per version. A second attempt updates the row rather than adding to a
            // pile nobody can choose between.
            $table->unique('version_id');
            $table->index(['document_id', 'status']);
        });

        DB::statement("ALTER TABLE document_extractions ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_extractions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_extractions FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_extractions_tenant ON document_extractions USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        /*
         * The index the content search runs on.
         *
         * 'simple' rather than 'english' to match the expression the title search already uses —
         * two different configurations would stem the same word differently, so a phrase found in a
         * title would go missing in the body of the same document.
         */
        DB::statement("CREATE INDEX document_extractions_text_search
            ON document_extractions USING gin (to_tsvector('simple', coalesce(text, '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
