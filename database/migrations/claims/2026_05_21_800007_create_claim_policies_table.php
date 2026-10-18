<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('rule_key', 80)->unique();
            $table->foreignId('category_id')->nullable()->constrained('claim_categories')->nullOnDelete();
            $table->enum('condition_type', ['amount_limit', 'age_limit', 'requires_receipt', 'requires_approval', 'requires_manager_approval', 'requires_executive_approval', 'blocked_days'])->default('amount_limit');
            $table->json('condition_value');
            $table->string('approval_role', 80)->nullable();
            $table->enum('action', ['warn', 'block', 'escalate'])->default('warn');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_policies');
    }
};
