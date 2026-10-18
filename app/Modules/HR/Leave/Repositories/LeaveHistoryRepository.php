<?php

namespace App\Modules\HR\Leave\Repositories;


use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaveHistoryRepository
{

    /**
     * Get leave history with optional filters.
     */
    public function getLeaveHistory(
        string $actionFrom = "",
        string $actionTo = "",
               $employees = null,
        string $leaveTypeId = "",
        string $date = ""
    ): Collection {
        $query = LeaveHistory::select(
            'leave_history.*',
            'hr_people.*',
            'leave_types.name as leave_type'
        )
            ->leftJoin('hr_people', 'leave_history.hr_id', '=', 'hr_people.id')
            ->leftJoin('leave_types', 'leave_history.leave_type_id', '=', 'leave_types.id');

        if ($actionFrom && $actionTo) {
            $query->whereBetween('leave_history.action_date', [$actionFrom, $actionTo]);
        }

        if (!empty($date)) {
            $query->where('leave_history.created_at', '>=', $date);
        }

        if (!empty($employees)) {
            $query->whereIn('leave_history.hr_id', (array) $employees);
        }

        if (!empty($leaveTypeId)) {
            $query->where('leave_history.leave_type_id', $leaveTypeId);
        }

        return $query->orderBy('hr_people.first_name')
            ->orderBy('hr_people.surname')
            ->orderBy('leave_history.action_date')
            ->orderBy('leave_types.name')
            ->get();
    }

    /**
     * Get current leave balance (without adjustments).
     */
    public function getLeaveBalance($employees, $leaveTypeId = ""): Collection
    {
        return HRPerson::select(
            'hr_people.*',
            'leave_credit.hr_id as userID',
            'leave_credit.leave_balance as Balance',
            'leave_credit.leave_type_id as LeaveID',
            'leave_types.name as leaveType',
            'division_level_fives.name as division_name',
            'division_level_fours.name as department_name'
        )
            ->leftJoin('leave_credit', 'leave_credit.hr_id', '=', 'hr_people.id')
            ->leftJoin('leave_types', 'leave_credit.leave_type_id', '=', 'leave_types.id')
            ->leftJoin('division_level_fives', 'hr_people.division_level_5', '=', 'division_level_fives.id')
            ->leftJoin('division_level_fours', 'hr_people.division_level_4', '=', 'division_level_fours.id')
            ->where('hr_people.status', 1)
            ->when(!empty($employees), fn($q) => $q->whereIn('hr_people.id', (array) $employees))
            ->when(!empty($leaveTypeId), fn($q) => $q->where('leave_credit.leave_type_id', $leaveTypeId))
            ->orderBy('hr_people.first_name')
            ->orderBy('hr_people.surname')
            ->orderBy('leave_types.name')
            ->get();
    }

    /**
     * Get updated leave balance considering applications and allocations after a start date.
     */
    public function getUpdatedLeaveBalance($employees, $leaveTypeId, $startDate): Collection
    {
        $leaveBalances = $this->getLeaveBalance($employees, $leaveTypeId);

        $allocationActions = [
            'leave days allocation',
            'Added leave Days',
            'Sick leave days allocation',
            'Family leave days allocation',
            'Paternity leave days allocation',
            'leave days reseted',
            'Added overtime Days'
        ];

        foreach ($leaveBalances as $balance) {
            $currentBalance = (float) ($balance->Balance ?? 0);

            $totalLeaveApplied = (float) DB::table('leave_application')
                ->where('hr_id', $balance->userID)
                ->when($balance->LeaveID, fn($q) => $q->where('leave_type_id', $balance->LeaveID))
                ->where('start_date', '>=', $startDate)
                ->where('status', 1)
                ->sum('leave_taken');

            $totalLeaveAllocated = (float) DB::table('leave_history')
                ->where('hr_id', $balance->userID)
                ->when($balance->LeaveID, fn($q) => $q->where('leave_type_id', $balance->LeaveID))
                ->where('action_date', '>=', $startDate)
                ->whereIn('action', $allocationActions)
                ->sum('transcation');

            $balance->Balance = $currentBalance + $totalLeaveApplied - $totalLeaveAllocated;
        }

        return $leaveBalances;
    }
}
