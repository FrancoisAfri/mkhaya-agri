<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — Core engine (Phase 1).
 *
 * Pipelines & stages, the four core entities (account, contact, lead, deal),
 * a unified activity timeline, a custom-fields engine for data-model
 * flexibility, and an AI-invocation audit log. Postgres connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Pipelines & stages (multi-pipeline support) ───────────────────────
        Schema::create('crm_pipelines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('team')->nullable();          // per team / region / product / BU
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('probability')->default(0); // 0-100 win likelihood
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('sla_days')->nullable();        // SLA per stage (escalation)
            $table->timestamps();

            $table->index(['pipeline_id', 'sort_order']);
        });

        // ── Accounts (companies) ──────────────────────────────────────────────
        Schema::create('crm_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->decimal('annual_revenue', 16, 2)->nullable();
            $table->unsignedInteger('employee_count')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        // ── Contacts (people) ─────────────────────────────────────────────────
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('job_title')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('email');
        });

        // ── Leads (top of funnel) ─────────────────────────────────────────────
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source')->nullable();        // web, referral, campaign, form…
            $table->string('status', 20)->default('new'); // new|contacted|qualified|unqualified|converted
            $table->unsignedTinyInteger('score')->nullable(); // 0-100 AI lead score
            $table->string('score_reason')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('converted_account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->unsignedBigInteger('converted_deal_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('email');
        });

        // ── Deals / opportunities ─────────────────────────────────────────────
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->foreignId('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('crm_stages')->cascadeOnDelete();
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->string('status', 10)->default('open'); // open|won|lost
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('source')->nullable();
            $table->string('risk_level', 10)->nullable();   // low|medium|high (AI)
            $table->string('risk_reason')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('stage_changed_at')->nullable(); // for SLA / inactivity
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['pipeline_id', 'stage_id']);
            $table->index('status');
        });

        // ── Unified activity timeline ─────────────────────────────────────────
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 20);                  // call|email|meeting|task|note|whatsapp|sms
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('related_type', 20);          // lead|contact|account|deal
            $table->unsignedBigInteger('related_id');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 12)->default('pending'); // pending|done|overdue|cancelled
            $table->json('ai_meta')->nullable();          // summary, sentiment, extracted fields
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index(['status', 'due_at']);
        });

        // ── Custom-field engine (data-model flexibility) ──────────────────────
        Schema::create('crm_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 20);           // lead|contact|account|deal
            $table->string('name');                       // machine key
            $table->string('label');
            $table->string('field_type', 20)->default('text'); // text|number|date|boolean|select|multiselect
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['entity_type', 'name']);
        });

        Schema::create('crm_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('crm_custom_fields')->cascadeOnDelete();
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'entity_type', 'entity_id'], 'crm_cfv_unique');
            $table->index(['entity_type', 'entity_id']);
        });

        // ── AI invocation audit ───────────────────────────────────────────────
        Schema::create('crm_ai_invocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature', 60);
            $table->string('ai_model', 80)->nullable();
            $table->integer('tokens_used')->nullable();
            $table->boolean('succeeded')->default(true);
            $table->timestamps();

            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_ai_invocations');
        Schema::dropIfExists('crm_custom_field_values');
        Schema::dropIfExists('crm_custom_fields');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_accounts');
        Schema::dropIfExists('crm_stages');
        Schema::dropIfExists('crm_pipelines');
    }
};
