<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('leave_configuration')->insert([
            [
                'id' => 1,
                'allow_annual_leave_credit' => 1,
                'allow_sick_leave_credit' => 1,
                'show_non_employees_in_leave_module' => 0,
                'require_managers_approval' => 1,
                'all_managers_to_approve' => null,
                'require_department_head_approval' => null,
                'require_hr_approval' => null,
                'require_payroll_approval' => null,
                'limit_administration_to_assigned_divisions' => null,
                'number_of_days_until_escalation' => 3,
                'document_compulsory_on_study_leave_application' => 1,
                'document_compulsory_when_two_sick_leave_8_weeks' => 1,
                'notify_hr_with_application' => 1,
                'preferred_communication_method' => 3,
                'notify_employee_about_applications_submitted' => null,
                'allow_sick_negative_days' => 10,
                'allow_annual_negative_days' => 5,
                'number_of_days_to_remind_manager' => 1,
                'hr_person_id' => 453,
                'ers_token_number' => '63f35b57a9d95db0162f4a4351865eaf',
                'number_of_days_before_automate_application' => 1,
                'password_update' => 1234567,
                'allow_unpaid_leave_when_annual_done' => 0,
                'created_at' => '2022-10-08 21:05:35',
                'updated_at' => '2025-01-21 08:37:55',
            ]
        ]);
    }
}
