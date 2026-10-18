<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drip sending: an optional per-day send cap. When set, a scheduled campaign
 * sends up to `daily_cap` recipients per run and reschedules itself for the next
 * day until the audience is exhausted — e.g. 100/day spread over several days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('daily_cap')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_campaigns', function (Blueprint $table) {
            $table->dropColumn('daily_cap');
        });
    }
};
