<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-class competency framework: a reusable library of competencies with
 * level-based behavioral anchors, mapped to roles (hr_positions) with an
 * expected level. Powers reviews, development and succession from one source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pa_competencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('category')->nullable(); // core | leadership | technical | functional
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('max_level')->default(5);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pa_competency_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_id')->constrained('pa_competencies')->cascadeOnDelete();
            $table->unsignedTinyInteger('level'); // 1..max_level
            $table->string('label')->nullable();
            $table->text('behavior')->nullable(); // behavioral anchor description
            $table->timestamps();

            $table->unique(['competency_id', 'level']);
        });

        Schema::create('pa_role_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_id')->constrained('pa_competencies')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('hr_positions')->cascadeOnDelete();
            $table->unsignedTinyInteger('expected_level')->default(3);
            $table->boolean('is_critical')->default(false);
            $table->timestamps();

            $table->unique(['competency_id', 'position_id']);
        });

        Schema::create('pa_employee_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('pa_competencies')->cascadeOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->unsignedTinyInteger('target_level')->nullable();
            $table->boolean('self_rated')->default(false);
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'competency_id', 'cycle_id'], 'pa_emp_comp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_employee_competencies');
        Schema::dropIfExists('pa_role_competencies');
        Schema::dropIfExists('pa_competency_anchors');
        Schema::dropIfExists('pa_competencies');
    }
};
