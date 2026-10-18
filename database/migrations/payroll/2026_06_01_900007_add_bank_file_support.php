<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_companies', function (Blueprint $table) {
            $table->string('bank')->nullable()->after('pay_frequency');            // employer's bank → file format
            $table->string('bank_account_name')->nullable()->after('bank');        // funding (debit) account
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('bank_branch_code')->nullable()->after('bank_account_number');
            $table->string('bank_user_code')->nullable()->after('bank_branch_code'); // bank-issued ACB user code
        });

        Schema::table('bureau_pay_runs', function (Blueprint $table) {
            $table->longText('bank_file_data')->nullable();   // encrypted at rest
            $table->string('bank_file_name')->nullable();
            $table->timestamp('bank_file_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bureau_pay_runs', fn (Blueprint $t) => $t->dropColumn(['bank_file_data', 'bank_file_name', 'bank_file_generated_at']));
        Schema::table('bureau_companies', fn (Blueprint $t) => $t->dropColumn(['bank', 'bank_account_name', 'bank_account_number', 'bank_branch_code', 'bank_user_code']));
    }
};
