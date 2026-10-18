<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GAP 1 — AQP/EISA framework ───────────────────────────────────────────

        Schema::create('lms_aqps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('acronym', 20);
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->string('registration_number', 60)->nullable()->unique();
            $table->string('website', 200)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
        });

        Schema::create('lms_eisa_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->unsignedBigInteger('learnership_id');
            $table->unsignedBigInteger('aqp_id');
            $table->unsignedBigInteger('hr_person_id');
            $table->date('scheduled_date');
            $table->string('venue', 200)->nullable();
            $table->string('status', 30)->default('scheduled');
            // scheduled | completed | absent | deferred | cancelled
            $table->decimal('score', 5, 2)->nullable();
            $table->string('result', 20)->nullable();
            // competent | not_yet_competent | absent
            $table->string('certificate_number', 80)->nullable();
            $table->date('certificate_issued_at')->nullable();
            $table->string('aqp_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->timestamps();
            $table->foreign('learnership_id')->references('id')->on('lms_learnerships')->cascadeOnDelete();
            $table->foreign('aqp_id')->references('id')->on('lms_aqps')->restrictOnDelete();
            $table->foreign('hr_person_id')->references('id')->on('hr_people')->cascadeOnDelete();
            $table->foreign('captured_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('lms_learnerships', function (Blueprint $table) {
            $table->unsignedBigInteger('aqp_id')->nullable()->after('seta_id');
            $table->foreign('aqp_id')->references('id')->on('lms_aqps')->nullOnDelete();
        });

        // GAP 5 — Logbook evidence in DB ──────────────────────────────────────

        Schema::table('lms_learnership_logbook', function (Blueprint $table) {
            $table->longText('evidence_file_data')->nullable()->after('evidence_file_path');
            $table->string('evidence_file_name', 255)->nullable()->after('evidence_file_data');
            $table->string('evidence_mime_type', 100)->nullable()->after('evidence_file_name');
            $table->unsignedInteger('evidence_size_bytes')->nullable()->after('evidence_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('lms_learnership_logbook', function (Blueprint $table) {
            $table->dropColumn(['evidence_file_data', 'evidence_file_name', 'evidence_mime_type', 'evidence_size_bytes']);
        });

        Schema::table('lms_learnerships', function (Blueprint $table) {
            $table->dropForeign(['aqp_id']);
            $table->dropColumn('aqp_id');
        });

        Schema::dropIfExists('lms_eisa_schedules');
        Schema::dropIfExists('lms_aqps');
    }
};
