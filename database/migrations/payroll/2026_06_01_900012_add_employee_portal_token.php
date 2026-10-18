<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service payslip portal: an unguessable token lets a bureau employee
 * (who has no platform login) retrieve their own payslips. The PDF itself stays
 * password-locked with their payslip key, so the token only lists; the key opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_employees', function (Blueprint $table) {
            $table->string('portal_token')->nullable()->unique()->after('payslip_key');
        });
    }

    public function down(): void
    {
        Schema::table('bureau_employees', fn (Blueprint $t) => $t->dropColumn('portal_token'));
    }
};
