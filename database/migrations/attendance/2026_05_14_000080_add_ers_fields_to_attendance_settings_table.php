<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // ERS Biometric Integration
            $table->boolean('ers_enabled')->default(false)->after('timezone');
            $table->string('ers_token')->nullable()->after('ers_enabled');
            $table->string('ers_api_url')->default('https://r14.ersbio.co.za/api/data_client.php')->after('ers_token');
            $table->time('ers_clock_start_time')->default('05:00:00')->after('ers_api_url');
            $table->time('ers_clock_end_time')->default('20:00:00')->after('ers_clock_start_time');

            // Absence notification + auto-leave
            $table->boolean('absent_email_enabled')->default(true)->after('ers_clock_end_time');
            $table->boolean('auto_apply_leave_enabled')->default(false)->after('absent_email_enabled');
            $table->unsignedTinyInteger('auto_apply_days_threshold')->default(3)->after('auto_apply_leave_enabled');
            $table->boolean('auto_deduct_leave')->default(false)->after('auto_apply_days_threshold');
            $table->boolean('allow_unpaid_when_annual_done')->default(false)->after('auto_deduct_leave');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ers_enabled', 'ers_token', 'ers_api_url',
                'ers_clock_start_time', 'ers_clock_end_time',
                'absent_email_enabled', 'auto_apply_leave_enabled',
                'auto_apply_days_threshold', 'auto_deduct_leave',
                'allow_unpaid_when_annual_done',
            ]);
        });
    }
};
