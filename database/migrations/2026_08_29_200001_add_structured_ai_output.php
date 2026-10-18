<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured output alongside the prose.
 *
 * A summary reads well and cannot be acted on by anything but a person. "Expires 30 June 2027,
 * ninety days' notice, R450,000" is the same information in a form the rest of the platform can
 * use — to fill an expiry date, to warn about a renewal, to answer a report.
 *
 * `data` holds it. `applied_at` records that somebody accepted a suggestion, which is what keeps
 * the two apart: a model's reading of a document is a proposal until a person agrees with it, and
 * writing extracted values straight onto a document would make every one of them look like
 * something a human checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_insights', function (Blueprint $table) {
            $table->jsonb('data')->nullable();

            // Null until somebody confirms. Never set by the extraction itself.
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();

            // Set when somebody looked at a suggestion and rejected it, so the same wrong reading is
            // not offered again on every visit to the document.
            $table->timestamp('dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_insights', function (Blueprint $table) {
            $table->dropColumn(['data', 'applied_at', 'applied_by', 'dismissed_at']);
        });
    }
};
