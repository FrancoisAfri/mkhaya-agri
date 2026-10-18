<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer complaints & compliments — captured from a public portal, a monitored
 * mailbox or logged manually. AI grades severity, identifies the employee
 * involved, assigns a handler and recommends corrective action. A separate
 * updates table records the lifecycle for SLA / cycle tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_complaints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference')->unique();              // e.g. CMP-2026-0001
            $table->string('type', 12)->default('complaint');   // complaint | compliment
            $table->string('source', 12)->default('portal');    // portal | mailbox | manual
            $table->string('subject');
            $table->longText('body');

            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();

            // Who it's about + who handles it.
            $table->foreignId('against_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('against_name')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            // AI grading.
            $table->string('severity', 10)->nullable();         // low|medium|high|critical
            $table->string('category', 30)->nullable();
            $table->string('sentiment', 10)->nullable();
            $table->longText('ai_summary')->nullable();
            $table->json('ai_recommendations')->nullable();     // corrective/disciplinary/training/coaching

            // Lifecycle + SLA.
            $table->string('status', 16)->default('new');       // new|acknowledged|in_progress|escalated|resolved|closed
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('resolve_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('escalated')->default(false);
            $table->timestamp('escalated_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'severity']);
            $table->index('against_employee_id');
        });

        Schema::create('crm_complaint_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('crm_complaints')->cascadeOnDelete();
            $table->string('type', 20);                          // status|assignment|note|escalation|ai
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_complaint_updates');
        Schema::dropIfExists('crm_complaints');
    }
};
