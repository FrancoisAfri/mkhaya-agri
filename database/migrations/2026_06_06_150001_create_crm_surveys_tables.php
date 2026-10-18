<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer surveys — built from best-practice templates, sent to the contacts of
 * selected accounts (by industry / spend / specific), answered via a public
 * token link, then analysed by AI for experience-improvement recommendations.
 * Questions and answers are stored as JSON to keep the model lean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_survey_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category', 40)->default('general');
            $table->text('description')->nullable();
            $table->json('questions');            // [{id,type,label,options?,required}]
            $table->timestamps();
        });

        Schema::create('crm_surveys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 40)->default('general');
            $table->json('questions');
            $table->json('audience')->nullable();  // {mode, industries[], min_spend, account_ids[]}
            $table->string('status', 12)->default('draft'); // draft|scheduled|active|closed
            $table->string('thank_you')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->longText('ai_recommendations')->nullable();
            $table->timestamp('ai_analyzed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_survey_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('crm_surveys')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['survey_id', 'completed_at']);
        });

        Schema::create('crm_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('crm_surveys')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('crm_survey_recipients')->nullOnDelete();
            $table->json('answers');               // {question_id: value}
            $table->integer('nps')->nullable();    // extracted NPS score 0-10 if present
            $table->integer('csat')->nullable();   // extracted CSAT 1-5 if present
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_survey_responses');
        Schema::dropIfExists('crm_survey_recipients');
        Schema::dropIfExists('crm_surveys');
        Schema::dropIfExists('crm_survey_templates');
    }
};
