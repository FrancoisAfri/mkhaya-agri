<?php

namespace App\Modules\HR\Payroll\Enums;

enum EarningsLineType: string
{
    case BasicSalary    = 'basic_salary';
    case Overtime       = 'overtime';
    case Bonus          = 'bonus';
    case Allowance      = 'allowance';
    case TravelAllowance = 'travel_allowance';
    case Commission     = 'commission';
    case LeavePayout    = 'leave_payout';   // leave encashment — an earning, from the Leave module
    case UnpaidLeave    = 'unpaid_leave';   // leave without pay — a deduction, from the Leave module
    case Paye           = 'paye';
    case Uif            = 'uif';
    case Pension        = 'pension';
    case MedicalAid     = 'medical_aid';
    case OtherDeduction = 'other_deduction';
    // Employer contributions: a company cost + a taxable fringe benefit to the employee.
    // They do NOT reduce net pay and are NOT employee earnings.
    case EmployerMedical = 'employer_medical';
    case EmployerPension = 'employer_pension';

    public function isDeduction(): bool
    {
        return in_array($this, [
            self::Paye,
            self::Uif,
            self::Pension,
            self::MedicalAid,
            self::UnpaidLeave,
            self::OtherDeduction,
        ]);
    }

    /** Employer-side contributions — count toward employer cost + the PAYE fringe base, not net. */
    public function isEmployerContribution(): bool
    {
        return in_array($this, [self::EmployerMedical, self::EmployerPension]);
    }
}
