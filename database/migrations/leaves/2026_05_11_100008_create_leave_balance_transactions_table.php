<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger for leave balance movements.
 * Rows MUST NOT be updated or deleted — each adjustment is a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('leave_cycle_id')->nullable()->constrained('leave_cycles')->nullOnDelete();

            $table->enum('transaction_type', [
                'opening_balance',
                'allocation',
                'manual_adjustment',
                'request_reservation',
                'request_release',
                'request_consumption',
                'payout',
                'carryover',
                'forfeiture',
                'correction',
            ]);

            // Positive = credit, negative = debit
            $table->decimal('hours', 8, 2);
            $table->decimal('balance_before', 10, 2);
            $table->decimal('balance_after', 10, 2);

            // Source reference
            $table->string('reference_type', 100)->nullable()
                ->comment('leave_request|manual|cron_allocation|payout|carryover');
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('description')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            // Append-only: only created_at, no updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'leave_type_id']);
            $table->index(['employee_id', 'leave_cycle_id']);
            $table->index('transaction_type');
            $table->index('created_at');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_transactions');
    }
};
