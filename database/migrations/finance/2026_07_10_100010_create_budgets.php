<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budgeting (Phase 5.3). A budget holds a per-account, per-period figure for a
 * fiscal year; variance is budget vs the actual GL movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_budgets', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name');
            $t->integer('fiscal_year');
            $t->string('period_type', 12)->default('monthly'); // monthly | annual
            $t->string('status', 12)->default('draft');        // draft | approved | locked
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'fiscal_year']);
        });

        Schema::create('fin_budget_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('budget_id');
            $t->unsignedBigInteger('account_id');
            $t->unsignedTinyInteger('period_no')->default(0); // 1-12 monthly, 0 = annual/whole-year
            $t->decimal('amount', 18, 2)->default(0);
            $t->string('dimension', 60)->nullable();          // project / branch tag (optional)
            $t->timestamps();
            $t->index(['budget_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_budget_lines');
        Schema::dropIfExists('fin_budgets');
    }
};
