<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — integrations hub (email / VoIP / WhatsApp / SMS providers) and incoming
 * mailboxes for AI lead capture. Provider credentials are stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();         // e.g. smtp, gmail, twilio, meta_whatsapp
            $table->string('category', 20);               // email|voip|whatsapp|sms
            $table->longText('config')->nullable();       // encrypted JSON credentials
            $table->boolean('active')->default(false);
            $table->string('status', 16)->default('disconnected'); // connected|disconnected|error
            $table->string('status_message')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('crm_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');                        // e.g. Sales inbox
            $table->string('email');                       // sales@mkhayamk.co.za
            $table->string('host');                        // imap host
            $table->unsignedSmallInteger('port')->default(993);
            $table->string('encryption', 8)->default('ssl'); // ssl|tls|none
            $table->string('username');
            $table->longText('password');                  // encrypted
            $table->string('folder')->default('INBOX');
            $table->boolean('active')->default(true);
            $table->boolean('ai_filter')->default(true);   // AI legitimacy/spam filtering
            $table->json('notify_roles')->nullable();      // roles to notify on new lead
            $table->foreignId('default_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('last_uid')->default(0); // highest processed message UID
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mailboxes');
        Schema::dropIfExists('crm_integrations');
    }
};
