<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery log for outbound notifications (mail / SMS / database / broadcast).
 * Populated by the NotificationSent / NotificationFailed listeners and read by the
 * platform System monitor. Tenant-tagged via company_id so per-company delivery
 * can be reported too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_logs')) {
            return;
        }

        Schema::create('notification_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('channel', 40)->default('mail');       // mail | vonage/sms | database | broadcast | ...
            $t->string('status', 12)->default('sent');         // sent | failed
            $t->string('notification', 191)->nullable();       // notification class (short)
            $t->string('notifiable_type', 191)->nullable();
            $t->unsignedBigInteger('notifiable_id')->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
