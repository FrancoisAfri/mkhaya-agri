<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FR-5.4 — Per-user notification preferences per channel
        Schema::create('fleet_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');           // email | sms | whatsapp | push | in_app
            $table->string('event_type');        // booking_approved | high_risk | anomaly | document_expiry | etc.
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'channel', 'event_type']);
        });

        // FR-5.1 — Web Push VAPID subscriptions (one per browser/device per user)
        Schema::create('fleet_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('p256dh_key', 512);
            $table->string('auth_key', 128);
            $table->string('device_label')->nullable();   // "Chrome on Android"
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique('endpoint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_push_subscriptions');
        Schema::dropIfExists('fleet_notification_preferences');
    }
};
