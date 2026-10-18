<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_number')->unique();
            $table->unsignedBigInteger('employee_id')->index();
            $table->decimal('amount_requested', 12, 2);
            $table->decimal('amount_approved', 12, 2)->nullable();
            $table->decimal('monthly_net_pay', 12, 2)->nullable();
            $table->decimal('percentage_of_net', 5, 2)->nullable();
            $table->date('repayment_month');
            $table->text('reason');
            // Snapshot fields
            $table->string('employment_status')->nullable();
            $table->boolean('is_permanent')->default(false);
            $table->boolean('probation_completed')->default(false);
            $table->boolean('is_suspended')->default(false);
            $table->enum('status', ['pending', 'approved', 'rejected', 'disbursed', 'settled'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('ai_flags')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->string('risk_level')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('employee_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
    }
};
