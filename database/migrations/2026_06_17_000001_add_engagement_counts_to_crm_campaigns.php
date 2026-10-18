<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement counters for campaign tracking. opened/clicked/sent/failed already
 * exist; these add the deliverability signals that come from an email provider's
 * event webhooks (bounce / spam complaint / blocked) plus unsubscribes, so the
 * campaign dashboard can show who to call and who to stop mailing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('bounced_count')->default(0)->after('failed_count');
            $table->unsignedInteger('complained_count')->default(0)->after('bounced_count');
            $table->unsignedInteger('unsubscribed_count')->default(0)->after('complained_count');
        });

        Schema::table('crm_campaign_recipients', function (Blueprint $table) {
            // When a provider reports a deliverability event we keep the raw
            // reason (e.g. "550 mailbox full", "spam complaint") for context.
            $table->string('bounce_reason')->nullable()->after('error');
            $table->timestamp('last_event_at')->nullable()->after('last_clicked_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_campaigns', function (Blueprint $table) {
            $table->dropColumn(['bounced_count', 'complained_count', 'unsubscribed_count']);
        });

        Schema::table('crm_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn(['bounce_reason', 'last_event_at']);
        });
    }
};
