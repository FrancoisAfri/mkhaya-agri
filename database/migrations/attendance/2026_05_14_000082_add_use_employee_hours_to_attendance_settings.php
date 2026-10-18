<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // When enabled, use start_time/end_time from employee profile instead of global defaults
            $table->boolean('use_employee_work_hours')->default(false)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn('use_employee_work_hours');
        });
    }
};
