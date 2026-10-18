<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->unique();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('claim_categories')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('currency', 10)->default('ZAR');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->date('claim_date');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'paid', 'cancelled'])->default('draft');
            $table->foreignId('current_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('approval_level')->default(0);
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('ai_flags')->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->nullable();
            $table->timestamp('ai_reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('claim_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
