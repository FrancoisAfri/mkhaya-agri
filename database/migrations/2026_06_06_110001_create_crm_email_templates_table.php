<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable email templates with merge tags (e.g. {{first_name}}). System
 * templates carry a stable `key` (new_lead, complaint_ack) the app sends
 * automatically; user templates have a null key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('key')->nullable()->unique();   // new_lead | complaint_ack | ...
            $table->string('category', 30)->default('general'); // lead|complaint|campaign|general
            $table->string('subject');
            $table->longText('body');                        // HTML with {{merge_tags}}
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_templates');
    }
};
