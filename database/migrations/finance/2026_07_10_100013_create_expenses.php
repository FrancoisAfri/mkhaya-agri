<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense management (Phase 5.5). Captures an expense / mileage claim and, on
 * approval, posts Dr expense (+ VAT input) / Cr the funding side (employee
 * reimbursement payable, petty cash, or company card/bank).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_expenses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();     // hr_people
            $t->string('category', 80)->nullable();
            $t->string('description', 255)->nullable();
            $t->unsignedBigInteger('expense_account_id');
            $t->decimal('amount', 18, 2)->default(0);              // net (excl VAT)
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->string('payment_method', 20)->default('reimbursable'); // reimbursable | petty_cash | company_card
            $t->string('contra_code', 20)->nullable();             // explicit funding account override
            $t->string('status', 12)->default('draft');            // draft | posted | rejected
            $t->date('expense_date');
            $t->boolean('is_mileage')->default(false);
            $t->decimal('km', 10, 2)->nullable();
            $t->decimal('rate', 10, 4)->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['status', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_expenses');
    }
};
