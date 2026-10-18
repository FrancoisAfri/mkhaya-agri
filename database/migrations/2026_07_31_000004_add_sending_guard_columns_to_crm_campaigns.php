<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a campaign be paused automatically when the sending domain's bounce or
 * complaint rate crosses the configured limit, and records why — otherwise a
 * campaign would simply stop with no explanation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_campaigns', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('sent_at');
            $table->string('pause_reason', 300)->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_campaigns', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'pause_reason']);
        });
    }
};
