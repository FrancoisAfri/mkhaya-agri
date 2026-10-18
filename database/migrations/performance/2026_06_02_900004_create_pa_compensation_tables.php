<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compensation / merit review: turn calibrated performance scores into merit %
 * and bonus recommendations per cycle, with an approval + budget view that ties
 * Performance to Payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pa_comp_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cycle_id')->constrained('pa_cycles')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft'); // draft | in_review | approved | applied
            $table->decimal('merit_budget_pct', 6, 2)->nullable();   // total budget as % of payroll
            $table->decimal('bonus_budget_pct', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pa_comp_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comp_review_id')->constrained('pa_comp_reviews')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('performance_score', 6, 2)->nullable();
            $table->string('rating_band')->nullable();          // derived band label
            $table->decimal('current_salary', 14, 2)->nullable();
            $table->decimal('suggested_merit_pct', 6, 2)->nullable();
            $table->decimal('final_merit_pct', 6, 2)->nullable();
            $table->decimal('suggested_bonus_pct', 6, 2)->nullable();
            $table->decimal('final_bonus_pct', 6, 2)->nullable();
            $table->decimal('new_salary', 14, 2)->nullable();
            $table->decimal('bonus_amount', 14, 2)->nullable();
            $table->string('status')->default('pending');       // pending | approved | rejected
            $table->text('justification')->nullable();
            $table->timestamps();

            $table->unique(['comp_review_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_comp_recommendations');
        Schema::dropIfExists('pa_comp_reviews');
    }
};
