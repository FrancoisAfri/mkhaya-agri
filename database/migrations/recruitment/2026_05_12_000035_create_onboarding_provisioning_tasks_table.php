<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_provisioning_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hr_person_id')->constrained('hr_persons')->cascadeOnDelete();
            $table->string('type', 50); // laptop|email_account|system_access|building_access|payroll_profile|phone|other
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending'); // pending|requested|in_progress|completed|cancelled
            $table->string('assigned_department', 100)->nullable(); // IT|HR|Facilities|Finance
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['hr_person_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_provisioning_tasks');
    }
};
