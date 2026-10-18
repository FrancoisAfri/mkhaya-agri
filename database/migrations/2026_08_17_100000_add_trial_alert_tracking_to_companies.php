<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets `tenancy:trial-reminders` warn platform admins before a trial runs out without
 * warning them again every morning.
 *
 * Two columns rather than a log table: the reminder is a countdown, so the only state
 * worth keeping is how far down the thresholds we have gone (`trial_alert_stage`, the
 * smallest number of days already alerted, -1 once the closing "it has lapsed" alert has
 * gone out) and which end date that refers to (`trial_alert_ends_at`). Extending a trial
 * moves the end date, which makes the stored stage stale and re-arms the whole ladder —
 * the alternative (a row per alert) would have to be reasoned about in exactly the same
 * way, with a table to garbage-collect.
 *
 * Signed: -1 is a real value here, so this cannot be an unsigned column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->smallInteger('trial_alert_stage')->nullable()->after('trial_ends_at');
            $t->date('trial_alert_ends_at')->nullable()->after('trial_alert_stage');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['trial_alert_stage', 'trial_alert_ends_at']);
        });
    }
};
