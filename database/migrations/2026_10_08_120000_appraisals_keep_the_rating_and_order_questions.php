<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the answer that was given, and let a template decide what order it asks in.
 *
 * `supplier_appraisal_responses` stored the weighted score and threw the rating away. The score is
 * derived — rating ÷ 5 × weight — so the thing a person actually did, "I gave them three out of
 * five", could not be read back. An appraisal could be totalled and never shown, which is most of
 * what an appraisal is for: the score settles a renewal, and the rating is the evidence behind it.
 * It is not reliably recoverable either, because a question weighted at zero scores zero whatever
 * the rating was.
 *
 * `sort_order` because a scorecard is read top to bottom and insertion order is not an order anybody
 * chose. Defaulted rather than backfilled per template: every existing question sorts at zero and
 * then falls back to its id, which is exactly the order they are in today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_appraisal_responses', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_appraisal_responses', 'rating')) {
                // Nullable: the rows written before this existed never recorded one, and inventing a
                // value for them would be worse than admitting it was not kept.
                $table->unsignedTinyInteger('rating')->nullable()->after('question_library_id');
            }
        });

        Schema::table('supplier_question_libraries', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_question_libraries', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('minimum_threshold');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_appraisal_responses', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_appraisal_responses', 'rating')) {
                $table->dropColumn('rating');
            }
        });

        Schema::table('supplier_question_libraries', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_question_libraries', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
