<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loan_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('min_amount', 12, 2)->default(0);
            $table->decimal('max_amount', 12, 2)->default(50000);
            $table->unsignedSmallInteger('min_term_months')->default(1);
            $table->unsignedSmallInteger('max_term_months')->default(60);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->enum('interest_type', ['none', 'fixed', 'reducing_balance'])->default('none');
            $table->boolean('requires_permanent_status')->default(true);
            $table->boolean('requires_probation_completion')->default(true);
            $table->decimal('executive_approval_threshold', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loan_types');
    }
};
