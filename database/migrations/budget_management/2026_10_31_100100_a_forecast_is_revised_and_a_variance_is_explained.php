<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reforecasting and variance explanations, on the period rows that already hold the plan.
 *
 *   forecast_*      what the budget owner now expects a period to cost. The run-rate projection
 *                   is arithmetic; it cannot know that the audit fee lands in March or that a
 *                   project was cancelled. The owner can, and this is where they say so.
 *   variance_*      why a closed period missed its plan. A number with no reason attached is a
 *                   question asked again at every management meeting.
 *
 * The threshold above which an explanation is required is set per financial year: what counts
 * as material changes with the size of the budget and the year's appetite for scrutiny.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_phasings', function (Blueprint $t) {
            if (! Schema::hasColumn('budget_phasings', 'forecast_amount')) {
                $t->decimal('forecast_amount', 18, 2)->nullable();
                $t->text('forecast_note')->nullable();
                $t->unsignedBigInteger('forecast_by')->nullable();
                $t->timestamp('forecast_at')->nullable();
            }
            if (! Schema::hasColumn('budget_phasings', 'variance_explanation')) {
                $t->text('variance_explanation')->nullable();
                $t->unsignedBigInteger('explained_by')->nullable();
                $t->timestamp('explained_at')->nullable();
            }
        });

        Schema::table('financial_years', function (Blueprint $t) {
            if (! Schema::hasColumn('financial_years', 'variance_threshold_pct')) {
                $t->decimal('variance_threshold_pct', 5, 2)->default(10);
            }
        });
    }

    public function down(): void
    {
        Schema::table('budget_phasings', fn (Blueprint $t) => $t->dropColumn([
            'forecast_amount', 'forecast_note', 'forecast_by', 'forecast_at',
            'variance_explanation', 'explained_by', 'explained_at',
        ]));
        Schema::table('financial_years', fn (Blueprint $t) => $t->dropColumn('variance_threshold_pct'));
    }
};
