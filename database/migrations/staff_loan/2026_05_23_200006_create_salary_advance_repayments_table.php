<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advance_id')->index();
            $table->date('due_date');
            $table->decimal('amount_due', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('method')->nullable();
            $table->boolean('payroll_deducted')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('advance_id')->references('id')->on('salary_advances')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advance_repayments');
    }
};
