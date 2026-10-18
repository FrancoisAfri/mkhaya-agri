<?php

namespace App\Shared\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait LeaveBalanceTrait
{
    public function getLeaveBalances(): array
    {
        $userId = Auth::id();

        // Find employee linked to logged-in user
        $employee = DB::table('hr_people')
            ->where('user_id', $userId)
            ->first();

        if (!$employee) {
            return [
                'error' => 'No linked employee'
            ];
        }

        // Get leave balances grouped by leave type
        $balances = DB::table('leave_credit')
            ->join('leave_types', 'leave_credit.leave_type_id', '=', 'leave_types.id')
            ->where('leave_credit.hr_id', $employee->id)
            ->select(
                'leave_types.name',
                'leave_credit.leave_balance'
            )
            ->get();

        // Get pending leave days
        $pending = DB::table('leave_applications')
            ->where('hr_id', $employee->id)
            ->where('status', 0) // Pending
            ->where('start_date', '>=', now()->timestamp)
            ->sum('leave_days');

        return [
            'balances' => $balances,
            'pending_days_requested' => $pending,
        ];
    }
}
