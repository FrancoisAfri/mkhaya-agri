<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Misconduct Categories ─────────────────────────────────────────────
        Schema::create('disciplinary_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('severity_level', 20)->default('minor'); // minor, serious, gross
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index('severity_level');
        });

        // ── Case Statuses ─────────────────────────────────────────────────────
        Schema::create('disciplinary_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('color', 30)->default('primary'); // vuetify color name
            $table->boolean('is_closed')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── Outcome Types ─────────────────────────────────────────────────────
        Schema::create('disciplinary_outcomes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('requires_sanction')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── Sanction Types ────────────────────────────────────────────────────
        Schema::create('disciplinary_sanctions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->string('severity_level', 20)->default('minor'); // minor, serious, gross
            $table->unsignedSmallInteger('duration_days')->nullable(); // e.g. suspension days
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index('severity_level');
        });

        // ── Module Settings ───────────────────────────────────────────────────
        Schema::create('disciplinary_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // ── Disciplinary Cases ────────────────────────────────────────────────
        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('case_number', 30)->unique();
            $table->unsignedBigInteger('employee_id');          // accused employee (users.id)
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('outcome_id')->nullable();
            $table->unsignedBigInteger('sanction_id')->nullable();
            $table->unsignedBigInteger('investigator_id')->nullable();
            $table->unsignedBigInteger('chairperson_id')->nullable();

            $table->string('title', 200);
            $table->text('description');
            $table->date('incident_date');
            $table->date('notice_issued_date')->nullable();
            $table->dateTime('hearing_date')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->string('policy_reference', 120)->nullable();
            $table->text('outcome_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->boolean('policy_engine_checked')->default(false);
            $table->json('policy_violations')->nullable();       // matched policy rules

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('status_id');
            $table->index('category_id');
            $table->index('investigator_id');
            $table->index('chairperson_id');
            $table->index('incident_date');

            $table->foreign('category_id')->references('id')->on('disciplinary_categories')->nullOnDelete();
            $table->foreign('status_id')->references('id')->on('disciplinary_statuses')->nullOnDelete();
            $table->foreign('outcome_id')->references('id')->on('disciplinary_outcomes')->nullOnDelete();
            $table->foreign('sanction_id')->references('id')->on('disciplinary_sanctions')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_cases');
        Schema::dropIfExists('disciplinary_settings');
        Schema::dropIfExists('disciplinary_sanctions');
        Schema::dropIfExists('disciplinary_outcomes');
        Schema::dropIfExists('disciplinary_statuses');
        Schema::dropIfExists('disciplinary_categories');
    }
};
