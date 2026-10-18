<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor number belongs to a client, not to the company.
 *
 * It was added to `fin_settings` — one number, printed on the letterhead of every document the
 * tenant issues. That is what a registration number is, and a vendor number is not that. It is the
 * supplier account the *client* opened for you on *their* purchase system, so it differs for every
 * client and, on the invoices that quote it, is the field their accounts-payable clerk matches
 * against before anything is paid. One shared number is wrong on every invoice but the first.
 *
 * So it moves down twice: onto the customer, where it is captured once and holds, and onto the
 * invoice and quote, where it is carried at the moment of issue and can still be corrected without
 * rewriting the customer record.
 *
 * The settings column is left in place and simply stops being read or offered. Dropping it would
 * discard whichever client's number happens to be sitting in it, and the value is more useful
 * where somebody can still look at it and decide who it belonged to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_parties', function (Blueprint $t) {
            $t->string('vendor_number', 60)->nullable()->after('registration_number');
        });

        Schema::table('fin_invoices', function (Blueprint $t) {
            $t->string('vendor_number', 60)->nullable()->after('party_ref');
        });

        Schema::table('fin_quotes', function (Blueprint $t) {
            $t->string('vendor_number', 60)->nullable()->after('customer_ref');
        });
    }

    public function down(): void
    {
        Schema::table('fin_parties', fn (Blueprint $t) => $t->dropColumn('vendor_number'));
        Schema::table('fin_invoices', fn (Blueprint $t) => $t->dropColumn('vendor_number'));
        Schema::table('fin_quotes', fn (Blueprint $t) => $t->dropColumn('vendor_number'));
    }
};
