<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Certificate templates ──────────────────────────────────────────────
        Schema::create('lms_certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->nullOnDelete();

            // Layout
            $table->enum('orientation', ['landscape', 'portrait'])->default('landscape');
            $table->string('background_color', 10)->default('#FFFFFF');
            $table->string('accent_color', 10)->default('#1E40AF');
            $table->string('background_image')->nullable(); // storage path
            $table->string('logo_url')->nullable();

            // Content placeholders: {{employee_name}}, {{course_title}}, {{issue_date}}, {{cert_number}}, {{expiry_date}}
            $table->string('title_text')->default('Certificate of Completion');
            $table->string('subtitle_text')->nullable();
            $table->text('body_html')->nullable();

            // Signatories
            $table->string('signatory_1_name')->nullable();
            $table->string('signatory_1_title')->nullable();
            $table->string('signatory_1_signature')->nullable(); // storage path
            $table->string('signatory_2_name')->nullable();
            $table->string('signatory_2_title')->nullable();
            $table->string('signatory_2_signature')->nullable();

            // Gate: require minimum understanding score before issuing
            $table->boolean('requires_understanding_score')->default(false);
            $table->tinyInteger('min_understanding_score')->default(70);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── Certification programs ─────────────────────────────────────────────
        Schema::create('lms_certifications', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('lms_certificate_templates')->nullOnDelete();

            // Validity
            $table->unsignedSmallInteger('valid_days')->nullable(); // null = never expires
            $table->unsignedSmallInteger('refresher_before_days')->default(30); // assign refresher N days before expiry
            $table->foreignId('refresher_course_id')->nullable()->constrained('lms_courses')->nullOnDelete();

            // Reminder schedule (days before expiry)
            $table->json('reminder_days')->default('[90,60,30,14,7,1]');

            // Access control when expired
            $table->json('actions_blocked_when_expired')->nullable(); // array of action keys

            // Gating: require understanding score before issuing
            $table->boolean('requires_understanding_score')->default(false);
            $table->tinyInteger('min_understanding_score')->default(70);

            // Auto-issue cert immediately on course completion
            $table->boolean('auto_issue')->default(true);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique('course_id', 'certifications_course_unique');
        });

        // ── Employee certification state ───────────────────────────────────────
        Schema::create('employee_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('certification_id')->constrained('lms_certifications')->cascadeOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained('lms_certificates')->nullOnDelete();

            $table->enum('status', ['pending', 'active', 'expired', 'revoked', 'refresher_required'])
                  ->default('pending');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->integer('reminders_sent')->default(0);
            $table->timestamp('last_reminder_at')->nullable();

            $table->timestamp('refresher_assigned_at')->nullable();
            $table->foreignId('refresher_enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->unique(['user_id', 'certification_id'], 'employee_cert_unique');
            $table->index(['status', 'expires_at']);
            $table->timestamps();
        });

        // ── Expiry event log ───────────────────────────────────────────────────
        Schema::create('certification_expiry_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_certification_id')->constrained('employee_certifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('certification_id')->constrained('lms_certifications')->cascadeOnDelete();

            $table->enum('event_type', [
                'issued', 'expired', 'reminder_sent',
                'refresher_assigned', 'action_blocked', 'revoked', 'renewed',
            ]);
            $table->integer('days_until_expiry')->nullable();
            $table->string('action_key')->nullable(); // populated for action_blocked events
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'certification_id', 'event_type']);
        });

        // ── Extend lms_certificates with template + certification links ─────────
        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('uuid')
                  ->constrained('lms_certificate_templates')->nullOnDelete();
            $table->foreignId('certification_id')->nullable()->after('template_id')
                  ->constrained('lms_certifications')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->after('expires_at')
                  ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropForeign(['certification_id']);
            $table->dropForeign(['issued_by']);
            $table->dropColumn(['template_id', 'certification_id', 'issued_by']);
        });

        Schema::dropIfExists('certification_expiry_logs');
        Schema::dropIfExists('employee_certifications');
        Schema::dropIfExists('lms_certifications');
        Schema::dropIfExists('lms_certificate_templates');
    }
};
