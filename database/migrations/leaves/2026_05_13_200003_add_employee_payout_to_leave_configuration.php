<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->boolean('allow_employee_payout_requests')->default(false)->after('balance_as_of_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->dropColumn('allow_employee_payout_requests');
        });
    }
};
