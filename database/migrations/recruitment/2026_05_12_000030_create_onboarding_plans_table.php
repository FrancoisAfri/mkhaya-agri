<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hr_person_id')->constrained('hr_persons')->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable(); // future: onboarding_templates
            $table->string('status', 30)->default('pending'); // pending|active|completed|cancelled
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hr_person_id', 'status']);
            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_plans');
    }
};
