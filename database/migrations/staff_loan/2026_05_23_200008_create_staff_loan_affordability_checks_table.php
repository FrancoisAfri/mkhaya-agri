<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loan_affordability_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->unsignedBigInteger('advance_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->decimal('monthly_net_pay', 12, 2);
            $table->decimal('existing_deductions', 12, 2)->default(0);
            $table->decimal('proposed_deduction', 10, 2);
            $table->decimal('total_deductions_after', 12, 2);
            $table->decimal('affordability_ratio', 6, 4);  // e.g. 0.2500 = 25%
            $table->decimal('disposable_income', 12, 2);
            $table->boolean('passed')->default(false);
            $table->text('failure_reason')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loan_affordability_checks');
    }
};
