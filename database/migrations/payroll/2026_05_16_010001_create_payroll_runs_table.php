<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 200);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 30)->default('draft'); // draft|processing|review|released|cancelled
            $table->decimal('total_gross', 14, 2)->nullable();
            $table->decimal('total_deductions', 14, 2)->nullable();
            $table->decimal('total_net', 14, 2)->nullable();
            $table->unsignedInteger('employee_count')->default(0);
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->string('ai_risk_level', 20)->nullable(); // low|medium|high|critical
            $table->json('ai_anomaly_summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
