<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — outbound webhooks (spec section 5, "API-first").
 *
 * A webhook subscribes to one or more CRM events; when an event fires every
 * matching active endpoint receives an HMAC-signed POST and the attempt is
 * recorded in crm_webhook_deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('url', 1024);
            $table->json('events');                 // ["lead.created","deal.won",...]
            $table->string('secret', 64);           // HMAC signing secret
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('crm_webhooks')->cascadeOnDelete();
            $table->string('event', 40);
            $table->json('payload');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_webhook_deliveries');
        Schema::dropIfExists('crm_webhooks');
    }
};
