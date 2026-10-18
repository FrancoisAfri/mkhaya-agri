<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_number')->unique();
            $table->foreignId('float_id')->constrained('petty_cash_floats')->restrictOnDelete();
            $table->foreignId('requestor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('custodian_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose');
            $table->string('category')->nullable(); // office_supplies, travel, maintenance, refreshments, other
            $table->decimal('amount_requested', 12, 2);
            $table->decimal('amount_approved', 12, 2)->nullable();
            $table->decimal('amount_issued', 12, 2)->nullable();
            $table->decimal('amount_spent', 12, 2)->nullable();
            $table->decimal('amount_returned', 12, 2)->nullable();
            $table->string('status')->default('draft'); // draft, pending, approved, rejected, issued, settled, cancelled
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('ai_flags')->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->string('risk_level')->nullable(); // low, medium, high, critical
            $table->boolean('receipt_submitted')->default(false);
            $table->timestamp('receipt_due_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_requests');
    }
};
