<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 80);           // run.created|run.employees_added|run.calculated|run.locked|run.submitted|run.released|run.cancelled
            $table->string('subject_type', 120)->nullable(); // PayrollRun|Payslip
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();        // extra context (employee count, totals, etc.)
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamps();

            $table->index(['run_id', 'action']);
            $table->index(['user_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_audit_logs');
    }
};
