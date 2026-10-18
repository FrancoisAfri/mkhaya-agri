<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_boarding_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hr_person_id')->constrained('hr_persons')->cascadeOnDelete();
            $table->string('status', 30)->default('pending'); // pending|in_progress|completed|cancelled
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hr_person_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_boarding_plans');
    }
};
