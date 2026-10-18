<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_policy_violations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_id')->nullable()->constrained('petty_cash_requests')->cascadeOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained('petty_cash_reconciliations')->cascadeOnDelete();
            $table->foreignId('float_id')->nullable()->constrained('petty_cash_floats')->cascadeOnDelete();
            $table->foreignId('detected_by_user')->nullable()->constrained('users')->nullOnDelete();
            $table->string('violation_type'); // limit_exceeded, prohibited_purpose, missing_receipt, self_approval, float_shortage, duplicate, suspicious
            $table->text('violation_description');
            $table->string('severity')->default('medium'); // low, medium, high, critical
            $table->string('policy_rule')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('open'); // open, resolved, escalated, waived
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_policy_violations');
    }
};
