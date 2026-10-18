<?php

namespace App\Modules\HR\Leave\Jobs;

use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeavePayoutRecord;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateLeavePayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(private readonly int $cycleId) {}

    public function handle(): void
    {
        $cycle = LeaveCycle::find($this->cycleId);

        if (! $cycle) {
            Log::warning("CalculateLeavePayoutJob: Cycle {$this->cycleId} not found.");
            return;
        }

        // Only paid leave types that allow payout at cycle end
        $paidLeaveTypeIds = LeaveType::where('status', 1)
            ->where('is_paid', true)
            ->whereNotNull('statutory_type')
            ->pluck('id')
            ->toArray();

        if (empty($paidLeaveTypeIds)) {
            return;
        }

        // Employees who have balance transactions in this cycle
        $employees = DB::table('leave_balance_transactions')
            ->where('leave_cycle_id', $cycle->id)
            ->whereIn('leave_type_id', $paidLeaveTypeIds)
            ->distinct()
            ->select('employee_id', 'leave_type_id')
            ->get();

        $payoutCount = 0;

        foreach ($employees as $row) {
            $balance = (float) (LeaveBalanceTransaction::where('employee_id', $row->employee_id)
                ->where('leave_type_id', $row->leave_type_id)
                ->where('leave_cycle_id', $cycle->id)
                ->latest('id')
                ->value('balance_after') ?? 0);

            if ($balance <= 0) {
                continue;
            }

            // Check if profile allocation allows payout (carry_over_max_hours < balance means remainder is paid out)
            $allocation = LeaveProfileAllocation::join('hr_people', 'hr_people.leave_profile_id', '=', 'leave_profile_allocations.leave_profile_id')
                ->where('hr_people.id', $row->employee_id)
                ->where('leave_profile_allocations.leave_type_id', $row->leave_type_id)
                ->where('leave_profile_allocations.active', true)
                ->select('leave_profile_allocations.*')
                ->first();

            if (! $allocation) {
                continue;
            }

            $carryMax   = $allocation->carry_over_max_hours !== null ? (float) $allocation->carry_over_max_hours : PHP_FLOAT_MAX;
            $payoutHours = max(0.0, $balance - $carryMax);

            if ($payoutHours <= 0) {
                continue;
            }

            DB::transaction(function () use ($row, $cycle, $payoutHours, $balance) {
                // Create payout record
                $payout = LeavePayoutRecord::create([
                    'employee_id'       => $row->employee_id,
                    'leave_type_id'     => $row->leave_type_id,
                    'leave_cycle_id'    => $cycle->id,
                    'hours_paid_out'    => $payoutHours,
                    'amount'            => 0,
                    'payout_date'       => $cycle->end_date,
                    'payroll_reference' => null,
                    'approved_by'       => null,
                    'status'            => 'pending',
                    'notes'             => "Cycle-end payout for {$cycle->name} — {$payoutHours}h balance exceeds carry-over cap",
                ]);

                // Debit the balance as a Payout transaction
                $balanceAfter = $balance - $payoutHours;
                LeaveBalanceTransaction::create([
                    'employee_id'      => $row->employee_id,
                    'leave_type_id'    => $row->leave_type_id,
                    'leave_cycle_id'   => $cycle->id,
                    'transaction_type' => LeaveTransactionType::Payout->value,
                    'hours'            => -$payoutHours,
                    'balance_before'   => $balance,
                    'balance_after'    => $balanceAfter,
                    'reference_type'   => 'leave_payout_record',
                    'reference_id'     => $payout->id,
                    'description'      => "Cycle-end payout — {$cycle->name}",
                    'performed_by'     => null,
                    'created_at'       => now(),
                ]);
            });

            $payoutCount++;
        }

        Log::info("CalculateLeavePayoutJob: Created {$payoutCount} payout record(s) for cycle {$cycle->name}.");
    }
}
