<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('run_id');
            $table->enum('current_level', ['payroll_officer', 'hr_manager', 'finance', 'executive'])
                  ->default('payroll_officer');
            $table->enum('status', ['pending', 'approved', 'rejected', 'escalated', 'info_requested'])
                  ->default('pending');
            $table->unsignedBigInteger('submitted_by');
            $table->date('due_date')->nullable();
            $table->boolean('high_risk')->default(false);
            $table->boolean('auto_escalated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('payroll_runs');

            $table->index('run_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_approvals');
    }
};
