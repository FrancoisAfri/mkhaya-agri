<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loan_policy_violations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->unsignedBigInteger('advance_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('violation_type');
            $table->text('violation_description');
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->text('policy_rule')->nullable();
            $table->json('metadata')->nullable();
            $table->enum('status', ['open', 'resolved', 'escalated'])->default('open');
            $table->timestamp('detected_at');
            $table->unsignedBigInteger('resolved_by')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loan_policy_violations');
    }
};
