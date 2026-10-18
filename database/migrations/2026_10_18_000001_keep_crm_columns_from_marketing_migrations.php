<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM columns that the Hub added inside its marketing migrations.
 *
 * Mkhaya Agri ships without Content Marketing and the AI Campaign Manager, so those migrations are
 * not here — but they also widened CRM tables the CRM still reads and writes (campaign tracking
 * increments automated_opens/clicks; leads and deals keep their attribution fields).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_campaigns') && ! Schema::hasColumn('crm_campaigns', 'automated_opens')) {
            Schema::table('crm_campaigns', function (Blueprint $table) {
                $table->unsignedInteger('automated_opens')->default(0);
                $table->unsignedInteger('automated_clicks')->default(0);
            });
        }

        foreach (['crm_deals', 'crm_leads'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'attribution')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->json('attribution')->nullable();
                $t->string('click_id')->nullable()->index();
                $t->unsignedBigInteger('marketing_campaign_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['crm_deals', 'crm_leads'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'attribution')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['attribution', 'click_id', 'marketing_campaign_id']));
            }
        }

        if (Schema::hasTable('crm_campaigns') && Schema::hasColumn('crm_campaigns', 'automated_opens')) {
            Schema::table('crm_campaigns', fn (Blueprint $t) => $t->dropColumn(['automated_opens', 'automated_clicks']));
        }
    }
};
