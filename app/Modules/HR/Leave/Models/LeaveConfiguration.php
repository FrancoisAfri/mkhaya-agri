<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveConfiguration extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveConfiguration')
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Leave configuration created',
                'updated' => 'Leave configuration updated',
                'deleted' => 'Leave configuration deleted',
                default   => "Leave configuration: {$e}",
            });
    }

    protected $table = 'leave_configuration';

    protected $fillable = [
        'allow_annual_leave_credit',
        'allow_sick_leave_credit',
        'show_non_employees_in_leave_module',
        'require_managers_approval',
        'all_managers_to_approve',
        'require_department_head_approval',
        'require_hr_approval',
        'require_payroll_approval',
        'limit_administration_to_assigned_divisions',
        'number_of_days_until_escalation',
        'document_compulsory_on_study_leave_application',
        'document_compulsory_when_two_sick_leave_8_weeks',
        'notify_hr_with_application',
        'preferred_communication_method',
        'notify_employee_about_applications_submitted',
        'allow_sick_negative_days',
        'allow_annual_negative_days',
        'number_of_days_to_remind_manager',
        'number_of_days_before_automate_application',
        'hr_person_id',
        'ers_token_number',
        'bulk_action_code',
        'password_update',
        'allow_unpaid_leave_when_annual_done',
        'require_delegation_approval',
        'manager_escalation_days',
        'department_escalation_days',
        'hr_escalation_days',
        'allow_delegation',
        'balance_as_of_enabled',
        'allow_employee_payout_requests',
        'notify_on_reject',
        'notify_on_escalate',
        'birthday_leave_enabled',
        'birthday_leave_days',
    ];

    protected $casts = [
        'allow_annual_leave_credit' => 'boolean',
        'allow_sick_leave_credit' => 'boolean',
        'show_non_employees_in_leave_module' => 'boolean',
        'require_managers_approval' => 'boolean',
        'all_managers_to_approve' => 'boolean',
        'require_department_head_approval' => 'boolean',
        'require_hr_approval' => 'boolean',
        'require_payroll_approval' => 'boolean',
        'limit_administration_to_assigned_divisions' => 'boolean',
        'document_compulsory_on_study_leave_application' => 'boolean',
        'document_compulsory_when_two_sick_leave_8_weeks' => 'boolean',
        'notify_hr_with_application' => 'boolean',
        'notify_employee_about_applications_submitted' => 'boolean',
        'allow_unpaid_leave_when_annual_done' => 'boolean',
        'require_delegation_approval'         => 'boolean',
        'allow_delegation'                    => 'boolean',
        'balance_as_of_enabled'               => 'boolean',
        'allow_employee_payout_requests'      => 'boolean',
        'notify_on_reject'                    => 'boolean',
        'notify_on_escalate'                  => 'boolean',
        'birthday_leave_enabled'              => 'boolean',
        'birthday_leave_days'                 => 'decimal:2',
    ];
}
