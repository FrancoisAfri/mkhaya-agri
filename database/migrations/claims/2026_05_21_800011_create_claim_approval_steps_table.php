<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('step_number');
            $table->string('step_label', 100);
            $table->string('approver_type', 30)->default('line_manager'); // line_manager | role | employee
            $table->string('role_name', 80)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount_min', 12, 2)->nullable()->comment('Apply step only if claim >= this amount');
            $table->decimal('amount_max', 12, 2)->nullable()->comment('Apply step only if claim <= this amount (null = no upper limit)');
            $table->boolean('applies_to_travel')->default(true);
            $table->boolean('applies_to_standard')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_approval_steps');
    }
};
