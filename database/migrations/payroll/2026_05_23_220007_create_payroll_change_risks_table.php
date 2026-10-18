<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_change_risks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('change_log_id');
            $table->string('risk_type', 100);
            $table->text('risk_description');
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->enum('flagged_by', ['system', 'user'])->default('system');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->enum('status', ['flagged', 'reviewed', 'cleared', 'escalated'])->default('flagged');
            $table->timestamps();

            $table->foreign('change_log_id')->references('id')->on('payroll_change_logs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_change_risks');
    }
};
