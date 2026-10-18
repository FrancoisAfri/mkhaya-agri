<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('float_id')->constrained('petty_cash_floats')->restrictOnDelete();
            $table->foreignId('reconciled_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 12, 2);
            $table->decimal('total_replenishments', 12, 2)->default(0);
            $table->decimal('total_issued', 12, 2)->default(0);
            $table->decimal('total_receipts', 12, 2)->default(0);
            $table->decimal('cash_on_hand', 12, 2);
            $table->decimal('closing_balance', 12, 2);
            $table->decimal('variance', 12, 2)->default(0); // positive = surplus, negative = shortage
            $table->string('status')->default('draft'); // draft, submitted, approved, rejected
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_reconciliations');
    }
};
