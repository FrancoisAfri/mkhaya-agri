<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_discipline_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discipline_case_id')->constrained('attendance_discipline_cases')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->string('pack_type', 30)->default('full')->comment('full,warning_letter,evidence_summary');
            $table->json('attendance_summary')->nullable();
            $table->json('violation_details')->nullable();
            $table->json('pattern_analysis')->nullable();
            $table->json('comparative_data')->nullable();
            $table->text('ai_narrative')->nullable();
            $table->text('warning_letter_content')->nullable();
            $table->text('recommended_sanction')->nullable();
            $table->json('supporting_references')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_discipline_packs');
    }
};
