<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which channels a host uses to tell contractors things (CON-19).
 *
 * The reminder sweep hard-coded EMAIL. The transport behind it has supported WhatsApp and SMS
 * the whole time, with WhatsApp falling back to SMS when it is unconfigured — and the docblock
 * on that adapter says in so many words that contractors are the audience that needs it.
 *
 * It matters more than a preference. A small contracting firm's registered email address is
 * frequently an accountant's, checked weekly; the number on the same record is the site
 * manager's, checked constantly. A notice that the gate has closed is worth nothing on Tuesday
 * if it shut on Monday morning.
 *
 * Email-only by default, because that is exactly what the module did before. Switching a host
 * onto WhatsApp without being asked would start sending to numbers nobody has checked are
 * right — and an unexpected message about site access, to a number that turns out to belong to
 * somebody who left, is worse than the slower channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_settings', function (Blueprint $table) {
            /*
             * A JSON list rather than a single choice: a host that wants the gate closing to
             * arrive by both email and WhatsApp is being sensible, not indecisive. The dispatch
             * table's unique key includes the channel, so one message per channel is exactly
             * what the dedupe guard already expects.
             */
            $table->jsonb('contractor_notification_channels')->nullable()
                ->after('contractor_blocking_rules');
        });

        /*
         * Existing tenants keep what they had. NULL reads as EMAIL in the notifier, so this is
         * belt and braces — but an explicit value means the settings screen shows a host what
         * is actually happening rather than an empty control they have to guess the meaning of.
         */
        DB::table('sheq_settings')->whereNull('contractor_notification_channels')
            ->update(['contractor_notification_channels' => json_encode(['EMAIL'])]);
    }

    public function down(): void
    {
        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->dropColumn('contractor_notification_channels');
        });
    }
};
