<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Incidents ─────────────────────────────────────────────────────────
        Schema::create('disciplinary_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('incident_number', 30)->unique();

            // Reporter (null = anonymous)
            $table->unsignedBigInteger('reporter_id')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->string('reporter_name', 150)->nullable();   // captured when anonymous
            $table->string('reporter_email', 150)->nullable();  // optional contact for anonymous

            // Classification
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title', 200);
            $table->text('description');
            $table->string('severity_level', 20)->default('minor'); // minor, serious, gross

            // Incident details
            $table->dateTime('incident_date');
            $table->string('location', 200)->nullable();
            $table->text('location_details')->nullable();

            // Workflow
            $table->string('status', 30)->default('open'); // open, under_investigation, escalated, closed, dismissed
            $table->unsignedBigInteger('investigator_id')->nullable();
            $table->dateTime('investigator_assigned_at')->nullable();
            $table->boolean('auto_assigned')->default(false);

            // Escalation to formal case
            $table->unsignedBigInteger('case_id')->nullable();

            $table->text('resolution_notes')->nullable();
            $table->dateTime('resolved_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('category_id');
            $table->index('investigator_id');
            $table->index('reporter_id');
            $table->index('incident_date');

            $table->foreign('category_id')->references('id')->on('disciplinary_categories')->nullOnDelete();
            $table->foreign('case_id')->references('id')->on('disciplinary_cases')->nullOnDelete();
        });

        // ── Witnesses ─────────────────────────────────────────────────────────
        Schema::create('disciplinary_witnesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('employee_id')->nullable(); // if internal employee
            $table->string('name', 150);
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->text('statement')->nullable();
            $table->dateTime('statement_date')->nullable();
            $table->timestamps();

            $table->index('incident_id');
            $table->foreign('incident_id')->references('id')->on('disciplinary_incidents')->cascadeOnDelete();
        });

        // ── Evidence ──────────────────────────────────────────────────────────
        Schema::create('disciplinary_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('file_type', 80)->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index('incident_id');
            $table->foreign('incident_id')->references('id')->on('disciplinary_incidents')->cascadeOnDelete();
        });

        // ── Accused ───────────────────────────────────────────────────────────
        Schema::create('disciplinary_accused', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('employee_id')->nullable(); // null if external/unknown
            $table->string('name', 150)->nullable();               // filled when not an employee
            $table->string('job_title', 150)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->text('description')->nullable();               // additional context about the accused
            $table->timestamps();

            $table->index('incident_id');
            $table->index('employee_id');
            $table->foreign('incident_id')->references('id')->on('disciplinary_incidents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_accused');
        Schema::dropIfExists('disciplinary_evidence');
        Schema::dropIfExists('disciplinary_witnesses');
        Schema::dropIfExists('disciplinary_incidents');
    }
};
