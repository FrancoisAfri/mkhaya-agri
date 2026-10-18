<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Summaries, and answers to questions asked about a document.
 *
 * Kept rather than regenerated because each one costs tokens from the company's monthly allowance,
 * and because the same summary shown twice should read the same both times — a summary that changes
 * wording every time it is opened is one nobody trusts enough to act on.
 *
 * Tied to the version, like extraction. A summary of the file that was replaced last week is not a
 * summary of this document, and keying on the document would leave it there looking current.
 *
 * Questions and answers are kept together with who asked. An assistant that reads confidential
 * documents and answers questions about them is a disclosure channel, and the log of what was asked
 * is the only way to see afterwards what it disclosed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_ai_insights', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('version_id')->nullable();

            // 'summary' or 'answer'.
            $table->string('kind', 16);

            // Null for a summary; the question as it was asked, for an answer.
            $table->text('question')->nullable();
            $table->longText('content')->nullable();

            /*
             * Whether the model said it could not answer from the document.
             *
             * Stored separately from the text because it is the difference between an answer and a
             * refusal, and a refusal counted as an answer makes the feature look more capable than
             * it is — which is how somebody comes to rely on one.
             */
            $table->boolean('grounded')->default(true);

            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('total_tokens')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'kind']);
            $table->index(['version_id', 'kind']);
        });

        DB::statement("ALTER TABLE document_ai_insights ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_ai_insights ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_ai_insights FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_ai_insights_tenant ON document_ai_insights USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        Schema::table('document_settings', function (Blueprint $table) {
            /*
             * Off until somebody turns it on, and separately from everything else in this module.
             *
             * Summarising a document sends its contents to whichever provider the platform is
             * configured with. That is a disclosure decision, not a convenience one, and an
             * organisation must make it deliberately rather than discover it was made for them.
             */
            $table->boolean('ai_enabled')->default(false);

            /*
             * And a second switch for the documents where the disclosure matters most.
             *
             * Confidential and legally sensitive documents are the ones an organisation is least
             * willing to hand to a third party, and they are also the ones somebody most wants
             * summarised. Off by default: a company that decides otherwise can say so.
             */
            $table->boolean('ai_include_confidential')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_ai_insights');

        Schema::table('document_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_include_confidential']);
        });
    }
};
