<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->unsignedSmallInteger('manager_escalation_days')->nullable()->after('number_of_days_to_remind_manager');
            $table->unsignedSmallInteger('department_escalation_days')->nullable()->after('manager_escalation_days');
            $table->unsignedSmallInteger('hr_escalation_days')->nullable()->after('department_escalation_days');
            $table->boolean('allow_delegation')->default(true)->after('hr_escalation_days');
            $table->boolean('notify_on_reject')->default(true)->after('notify_employee_about_applications_submitted');
            $table->boolean('notify_on_escalate')->default(true)->after('notify_on_reject');
        });
    }

    public function down(): void
    {
        Schema::table('leave_configuration', function (Blueprint $table) {
            $table->dropColumn([
                'manager_escalation_days',
                'department_escalation_days',
                'hr_escalation_days',
                'allow_delegation',
                'notify_on_reject',
                'notify_on_escalate',
            ]);
        });
    }
};
