<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_notification_configs', function (Blueprint $table) {
            $table->string('teams_webhook_url')->nullable()->after('custom_message');
            $table->string('slack_webhook_url')->nullable()->after('teams_webhook_url');
            $table->boolean('whatsapp_enabled')->default(false)->after('slack_webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_notification_configs', function (Blueprint $table) {
            $table->dropColumn(['teams_webhook_url', 'slack_webhook_url', 'whatsapp_enabled']);
        });
    }
};
