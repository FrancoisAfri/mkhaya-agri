<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_companies', fn (Blueprint $t) => $t->boolean('auto_email_payslips')->default(false)->after('bank_user_code'));
    }

    public function down(): void
    {
        Schema::table('bureau_companies', fn (Blueprint $t) => $t->dropColumn('auto_email_payslips'));
    }
};
