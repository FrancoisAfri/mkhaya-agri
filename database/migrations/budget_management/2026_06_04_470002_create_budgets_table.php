<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets: department / project / category / general budgets, CAPEX or OPEX,
 * with an allocated amount. Reserved / committed / actual are derived from the
 * ledger tables; available = allocated (+ approved overrides) − the three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('financial_year_id')->nullable()->index();
            $table->unsignedBigInteger('budget_period_id')->nullable();
            $table->unsignedBigInteger('budget_category_id')->nullable();
            $table->string('expenditure_type')->default('opex'); // capex | opex
            $table->string('scope_type')->default('general');    // department | project | category | general
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_label')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->decimal('allocated_amount', 18, 2)->default(0);
            $table->string('status')->default('draft'); // draft|pending|approved|active|closed|rejected
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
