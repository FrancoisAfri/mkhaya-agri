<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_persons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $table->foreignId('contract_id')->constrained('recruitment_contracts')->restrictOnDelete();
            $table->string('employee_number', 20)->unique()->nullable();
            $table->date('started_on')->nullable();
            $table->string('status', 30)->default('pre_boarding'); // pre_boarding|onboarding|active|terminated|resigned
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_persons');
    }
};
