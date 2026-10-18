<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probation_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hr_person_id')->constrained('hr_persons')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('extended_end_date')->nullable();
            $table->unsignedTinyInteger('duration_months')->default(3);
            $table->string('status', 30)->default('active'); // active|completed|extended|failed
            $table->string('outcome', 30)->nullable(); // confirmed|extended|dismissed
            $table->text('outcome_notes')->nullable();
            $table->date('next_review_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['hr_person_id', 'status']);
            $table->index(['next_review_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probation_periods');
    }
};
