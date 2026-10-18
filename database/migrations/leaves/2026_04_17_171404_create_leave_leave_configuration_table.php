<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_configuration', function (Blueprint $table) {
            $table->id();
            $table->boolean('allow_annual_leave_credit')->nullable();
            $table->boolean('allow_sick_leave_credit')->nullable();
            $table->boolean('show_non_employees_in_leave_module')->nullable();
            $table->boolean('require_managers_approval')->nullable();
            $table->boolean('all_managers_to_approve')->nullable();
            $table->boolean('require_department_head_approval')->nullable();
            $table->boolean('require_hr_approval')->nullable();
            $table->boolean('require_payroll_approval')->nullable();
            $table->boolean('limit_administration_to_assigned_divisions')->nullable();
            $table->integer('number_of_days_until_escalation')->nullable();
            $table->boolean('document_compulsory_on_study_leave_application')->nullable();
            $table->boolean('document_compulsory_when_two_sick_leave_8_weeks')->nullable();
            $table->boolean('notify_hr_with_application')->nullable();
            $table->smallInteger('preferred_communication_method')->nullable();
            $table->boolean('notify_employee_about_applications_submitted')->nullable();
            $table->integer('allow_sick_negative_days')->nullable();
            $table->integer('allow_annual_negative_days')->nullable();
            $table->bigInteger('number_of_days_to_remind_manager')->default(0);
            $table->integer('number_of_days_before_automate_application')->default(0);
            $table->bigInteger('hr_person_id')->default(0);
            $table->string('ers_token_number')->nullable();
            $table->bigInteger('password_update')->default(0);
            $table->boolean('allow_unpaid_leave_when_annual_done')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_configuration');
    }
};
