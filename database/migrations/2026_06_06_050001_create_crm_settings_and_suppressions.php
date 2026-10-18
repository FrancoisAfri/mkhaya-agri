<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — in-app settings (e.g. WhatsApp credentials, configured in the UI rather
 * than .env) and the marketing suppression list (unsubscribes / bounces).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();   // encrypted for secret keys
            $table->timestamps();
        });

        Schema::create('crm_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10)->default('email');  // email|whatsapp
            $table->string('address');                         // email or phone
            $table->string('reason', 30)->default('unsubscribed'); // unsubscribed|bounced|complained|manual
            $table->foreignId('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['channel', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_suppressions');
        Schema::dropIfExists('crm_settings');
    }
};
