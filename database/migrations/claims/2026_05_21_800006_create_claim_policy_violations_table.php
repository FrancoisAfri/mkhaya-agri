<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_policy_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->string('policy_rule', 80);
            $table->enum('violation_type', ['amount_exceeded', 'age_exceeded', 'missing_receipt', 'duplicate', 'inflated', 'splitting', 'out_of_policy', 'weekend_claim', 'after_hours', 'other'])->default('other');
            $table->text('description');
            $table->enum('severity', ['warning', 'block'])->default('warning');
            $table->boolean('is_exception_requested')->default(false);
            $table->boolean('exception_approved')->default(false);
            $table->foreignId('exception_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('exception_reason')->nullable();
            $table->timestamp('exception_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_policy_violations');
    }
};
