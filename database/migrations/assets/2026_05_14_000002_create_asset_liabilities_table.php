<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_liabilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exit_clearance_id')->constrained('asset_exit_clearances')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('employee_id')->constrained('users');
            
            $table->enum('liability_type', ['missing', 'damaged', 'lost', 'stolen']);
            
            $table->decimal('asset_book_value', 12, 2);
            $table->decimal('liability_amount', 12, 2);
            
            $table->enum('settlement_status', ['pending', 'deducted', 'waived', 'paid'])->default('pending');
            $table->date('settlement_date')->nullable();
            $table->enum('settlement_method', ['payroll_deduction', 'cash', 'waived'])->nullable();
            
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['employee_id', 'settlement_status']);
            $table->index('exit_clearance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_liabilities');
    }
};
