<?php

namespace App\Modules\HR\Leave\Enums;

enum LeaveAnomalyType: string
{
    case MondayFridayAbuse  = 'monday_friday_abuse';
    case HolidayAbuse       = 'holiday_abuse';
    case ExcessiveSick      = 'excessive_sick';
    case NegativeBalance    = 'negative_balance';
    case BurnoutRisk        = 'burnout_risk';
    case UnderstaffingRisk  = 'understaffing_risk';
    case PatternDetected    = 'pattern_detected';
    case ConcurrentAbuse    = 'concurrent_abuse';
    case PolicyViolation    = 'policy_violation';
    // Account-setup / ledger-integrity anomalies (why leave silently never runs)
    case NoLeaveProfile     = 'no_leave_profile';
    case MissingAccrual     = 'missing_accrual';
    case BalanceMismatch    = 'balance_mismatch';
    case MissingBirthdate   = 'missing_birthdate';
    case MissingJoinDate    = 'missing_join_date';
    case NoSickLeaveSetup   = 'no_sick_leave_setup';
    case OvertimeNotCredited = 'overtime_not_credited';
    // Cron-health + attendance cross-checks (weekly, last-week scoped)
    case UnauthorisedAbsence = 'unauthorised_absence';
    case StaleAccrual        = 'stale_accrual';
    case SickCycleOverdue    = 'sick_cycle_overdue';
    case RecentNegativeTxn   = 'recent_negative_txn';

    public function label(): string
    {
        return match($this) {
            self::MondayFridayAbuse => 'Monday/Friday Pattern',
            self::HolidayAbuse      => 'Holiday Abuse',
            self::ExcessiveSick     => 'Excessive Sick Leave',
            self::NegativeBalance   => 'Negative Balance',
            self::BurnoutRisk       => 'Burnout Risk',
            self::UnderstaffingRisk => 'Understaffing Risk',
            self::PatternDetected   => 'Pattern Detected',
            self::ConcurrentAbuse   => 'Concurrent Abuse',
            self::PolicyViolation   => 'Policy Violation',
            self::NoLeaveProfile    => 'No Leave Profile',
            self::MissingAccrual    => 'Missing Accrual',
            self::BalanceMismatch   => 'Balance Mismatch',
            self::MissingBirthdate  => 'Missing Date of Birth',
            self::MissingJoinDate   => 'Missing Join Date',
            self::NoSickLeaveSetup  => 'No Sick Leave Setup',
            self::OvertimeNotCredited => 'Overtime Not Credited',
            self::UnauthorisedAbsence => 'Unauthorised Absence',
            self::StaleAccrual      => 'Accrual Did Not Run',
            self::SickCycleOverdue  => 'Sick Leave Cycle Overdue',
            self::RecentNegativeTxn => 'Recent Overdraw',
        };
    }

    public function severity(): string
    {
        return match($this) {
            self::BurnoutRisk, self::UnderstaffingRisk => 'high',
            self::MondayFridayAbuse, self::HolidayAbuse,
            self::ExcessiveSick, self::PolicyViolation  => 'medium',
            self::NegativeBalance                        => 'high',
            // Account-setup problems mean leave never runs → high impact.
            self::NoLeaveProfile, self::MissingAccrual,
            self::MissingJoinDate                        => 'high',
            // Cron didn't run / unauthorised absence → high (statutory + payroll impact).
            self::StaleAccrual, self::SickCycleOverdue,
            self::UnauthorisedAbsence, self::RecentNegativeTxn => 'high',
            self::BalanceMismatch, self::OvertimeNotCredited => 'medium',
            self::MissingBirthdate, self::NoSickLeaveSetup   => 'medium',
            default                                      => 'low',
        };
    }

    /** Anomalies fixable by a ledger correction/accrual vs. those needing HR to capture data. */
    public function isDataCapture(): bool
    {
        return in_array($this, [self::MissingBirthdate, self::MissingJoinDate, self::NoLeaveProfile, self::NoSickLeaveSetup]);
    }
}
