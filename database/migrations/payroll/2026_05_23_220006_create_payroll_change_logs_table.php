<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_change_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('payslip_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->string('field_changed', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->enum('change_type', ['manual', 'system', 'correction', 'import'])->default('system');
            $table->unsignedBigInteger('changed_by');
            $table->text('change_reason')->nullable();
            $table->tinyInteger('risk_score')->default(0);
            $table->enum('risk_level', ['none', 'low', 'medium', 'high', 'critical'])->default('none');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_change_logs');
    }
};
