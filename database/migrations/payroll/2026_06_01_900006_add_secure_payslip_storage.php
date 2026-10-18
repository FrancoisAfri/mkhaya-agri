<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secure payslip storage for normal (non-bureau) payroll: each employee gets a
 * payslip lock key (encrypted), and released payslips keep their encrypted PDF
 * in the DB under the employee's profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_people', 'payslip_key')) {
            Schema::table('hr_people', fn (Blueprint $t) => $t->text('payslip_key')->nullable()->after('uuid'));
        }
        Schema::table('payslips', function (Blueprint $table) {
            if (! Schema::hasColumn('payslips', 'pdf_data')) {
                $table->longText('pdf_data')->nullable();
            }
            if (! Schema::hasColumn('payslips', 'is_locked')) {
                $table->boolean('is_locked')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn(['pdf_data', 'is_locked']));
        Schema::table('hr_people', fn (Blueprint $t) => $t->dropColumn('payslip_key'));
    }
};
