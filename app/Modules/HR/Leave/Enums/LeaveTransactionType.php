<?php

namespace App\Modules\HR\Leave\Enums;

enum LeaveTransactionType: string
{
    case OpeningBalance      = 'opening_balance';
    case Allocation          = 'allocation';
    case ManualAdjustment    = 'manual_adjustment';
    case RequestReservation  = 'request_reservation';
    case RequestRelease      = 'request_release';
    case RequestConsumption  = 'request_consumption';
    case Payout              = 'payout';
    case Carryover           = 'carryover';
    case Forfeiture          = 'forfeiture';
    case Correction          = 'correction';
    case OvertimeCredit      = 'overtime_credit';

    public function label(): string
    {
        return match($this) {
            self::OpeningBalance     => 'Opening Balance',
            self::Allocation         => 'Allocation',
            self::ManualAdjustment   => 'Manual Adjustment',
            self::RequestReservation => 'Balance Reserved',
            self::RequestRelease     => 'Balance Released',
            self::RequestConsumption => 'Leave Consumed',
            self::Payout             => 'Paid Out',
            self::Carryover          => 'Carry Over',
            self::Forfeiture         => 'Forfeiture',
            self::Correction         => 'Correction',
            self::OvertimeCredit     => 'Overtime Credit',
        };
    }

    public function isCredit(): bool
    {
        return in_array($this, [
            self::OpeningBalance,
            self::Allocation,
            self::ManualAdjustment,
            self::RequestRelease,
            self::Carryover,
            self::Correction,
            self::OvertimeCredit,
        ]);
    }
}
