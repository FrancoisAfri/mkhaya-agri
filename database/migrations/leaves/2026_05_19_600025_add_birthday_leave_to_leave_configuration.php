<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->boolean('birthday_leave_enabled')->default(false)->after('allow_employee_payout_requests');
            $table->decimal('birthday_leave_days', 4, 2)->nullable()->after('birthday_leave_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->dropColumn(['birthday_leave_enabled', 'birthday_leave_days']);
        });
    }
};
