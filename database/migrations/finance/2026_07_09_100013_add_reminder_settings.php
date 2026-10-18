<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable auto-reminder cadence: when enabled, a daily job emails a
 * reminder to customers whose invoices hit one of the configured days-overdue
 * thresholds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_settings', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(false)->after('default_message');
            $table->json('reminder_days')->nullable()->after('reminders_enabled'); // e.g. [7,14,30]
        });
    }

    public function down(): void
    {
        Schema::table('fin_settings', fn (Blueprint $table) => $table->dropColumn(['reminders_enabled', 'reminder_days']));
    }
};
