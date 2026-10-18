<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_payout_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('leave_cycle_id')->nullable()->constrained('leave_cycles')->nullOnDelete();

            $table->decimal('hours_paid_out', 8, 2);
            $table->decimal('amount', 15, 2)->nullable();
            $table->date('payout_date');

            $table->string('payroll_reference', 100)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', ['pending', 'processed', 'reversed'])->default('pending');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('employee_id');
            $table->index('leave_type_id');
            $table->index(['status', 'payout_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_payout_records');
    }
};
