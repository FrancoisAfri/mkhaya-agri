<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is told when a monthly report is issued.
 *
 * Directors and executives who never open Project Management but need the report the moment it is
 * ready. Held as a list of Hub user ids on the module's settings row rather than as a table of its
 * own: it is one short list per tenant, edited on one screen, and a join table would be three files
 * to express "these five people".
 *
 * Stored beside the invoicing details because both answer the same question — what this practice's
 * Project Management does by default — and a tenant editing one is on the screen for the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_invoicing_settings', function (Blueprint $t) {
            $t->json('report_recipient_ids')->nullable()->after('account_description');
            // Additional addresses for people who are not Hub users at all — a board member, an
            // external financial adviser. Kept separate so removing a user cannot silently drop one.
            $t->text('report_recipient_emails')->nullable()->after('report_recipient_ids');
        });
    }

    public function down(): void
    {
        Schema::table('pm_invoicing_settings', function (Blueprint $t) {
            $t->dropColumn(['report_recipient_ids', 'report_recipient_emails']);
        });
    }
};
