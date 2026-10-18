<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A vendor number is the identifier a CUSTOMER files us under in their own
 * purchasing system. It is not our VAT or registration number — those say who
 * we are to the state; this says who we are to the buyer, and an invoice
 * missing it can sit unmatched in their accounts payable until somebody phones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_settings', function (Blueprint $t) {
            $t->string('vendor_number', 60)->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('fin_settings', fn (Blueprint $t) => $t->dropColumn('vendor_number'));
    }
};
