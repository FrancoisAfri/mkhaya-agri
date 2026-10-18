<?php

namespace App\Modules\HR\Leave\Jobs;

use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdvanceLeaveCyclesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function handle(): void
    {
        $activeCycle = LeaveCycle::where('status', 'active')->first();

        if (! $activeCycle) {
            Log::warning('AdvanceLeaveCyclesJob: No active cycle found.');
            return;
        }

        if ($activeCycle->end_date->isFuture()) {
            return;
        }

        DB::transaction(function () use ($activeCycle) {
            // 1. Apply forfeiture before closing (balances expire)
            $this->processForfeiture($activeCycle);

            // 2. Close the current cycle
            $activeCycle->update(['status' => 'closed']);

            // 3. Process carry-overs for all employees
            if ($activeCycle->auto_advance) {
                $this->processCarryOvers($activeCycle);
            }

            // 4. Open the next cycle — use a pre-created 'pending' one if it exists,
            //    otherwise AUTO-CREATE it so accrual never stalls (important at scale
            //    where nobody remembers to create next year's cycle per tenant).
            $nextCycle = LeaveCycle::where('status', 'pending')
                ->where('start_date', '>', $activeCycle->end_date)
                ->orderBy('start_date')
                ->first();

            if (! $nextCycle) {
                $start = $activeCycle->end_date->copy()->addDay();
                $end   = $activeCycle->cycle_type === 'monthly'
                    ? $start->copy()->addMonthNoOverflow()->subDay()
                    : $start->copy()->addYearNoOverflow()->subDay();
                $nextCycle = LeaveCycle::create([
                    'name'         => 'Leave Cycle ' . $start->year,
                    'start_date'   => $start->toDateString(),
                    'end_date'     => $end->toDateString(),
                    'cycle_type'   => $activeCycle->cycle_type,
                    'year'         => $start->year,
                    'status'       => 'pending',
                    'auto_advance' => $activeCycle->auto_advance,
                    'created_by'   => $activeCycle->created_by,
                ]);
                Log::info("AdvanceLeaveCyclesJob: Auto-created next cycle {$nextCycle->name}.");
            }

            $nextCycle->update(['status' => 'active']);
            Log::info("AdvanceLeaveCyclesJob: Advanced to cycle {$nextCycle->name}.");

            // 5. Trigger yearly allocation for the new cycle (runs in this tenant
            //    context; the queue payload carries the company so it stays scoped).
            AllocateLeaveBalancesJob::dispatch('yearly');
        });

        // 6. Payout calculation runs after the transaction closes (needs closed cycle state)
        CalculateLeavePayoutJob::dispatch($activeCycle->id);
    }

    private function processForfeiture(LeaveCycle $cycle): void
    {
        // Leave types that have forfeiture enabled (use_it-or-lose-it — no carry-over or explicit forfeiture_after_days)
        $forfeitableTypeIds = LeaveType::where('status', 1)
            ->where(fn($q) => $q->where('carry_over_enabled', false)->orWhereNull('carry_over_enabled'))
            ->pluck('id')
            ->toArray();

        if (empty($forfeitableTypeIds)) {
            return;
        }

        $employees = DB::table('leave_balance_transactions')
            ->where('leave_cycle_id', $cycle->id)
            ->whereIn('leave_type_id', $forfeitableTypeIds)
            ->distinct()
            ->select('employee_id', 'leave_type_id')
            ->get();

        foreach ($employees as $row) {
            $balance = (float) (LeaveBalanceTransaction::where('employee_id', $row->employee_id)
                ->where('leave_type_id', $row->leave_type_id)
                ->where('leave_cycle_id', $cycle->id)
                ->latest('id')
                ->value('balance_after') ?? 0);

            if ($balance <= 0) {
                continue;
            }

            LeaveBalanceTransaction::create([
                'employee_id'      => $row->employee_id,
                'leave_type_id'    => $row->leave_type_id,
                'leave_cycle_id'   => $cycle->id,
                'transaction_type' => LeaveTransactionType::Forfeiture->value,
                'hours'            => -$balance,
                'balance_before'   => $balance,
                'balance_after'    => 0,
                'reference_type'   => 'cycle_advance',
                'reference_id'     => $cycle->id,
                'description'      => "Balance forfeited at cycle close — {$cycle->name}",
                'performed_by'     => null,
                'created_at'       => now(),
            ]);
        }
    }

    private function processCarryOvers(LeaveCycle $closedCycle): void
    {
        $nextCycle = LeaveCycle::where('status', 'active')->first();
        if (! $nextCycle) {
            return;
        }

        $employees = DB::table('leave_balance_transactions')
            ->where('leave_cycle_id', $closedCycle->id)
            ->distinct()
            ->pluck('employee_id');

        foreach ($employees as $employeeId) {
            $allocations = LeaveProfileAllocation::where('active', true)
                ->whereNotNull('carry_over_max_hours')
                ->where('carry_over_max_hours', '>', 0)
                ->get();

            foreach ($allocations as $alloc) {
                $balance = (float) (LeaveBalanceTransaction::where('employee_id', $employeeId)
                    ->where('leave_type_id', $alloc->leave_type_id)
                    ->where('leave_cycle_id', $closedCycle->id)
                    ->latest('id')
                    ->value('balance_after') ?? 0);

                if ($balance <= 0) {
                    continue;
                }

                $carryHours = min($balance, (float) $alloc->carry_over_max_hours);

                LeaveBalanceTransaction::create([
                    'employee_id'      => $employeeId,
                    'leave_type_id'    => $alloc->leave_type_id,
                    'leave_cycle_id'   => $nextCycle->id,
                    'transaction_type' => LeaveTransactionType::Carryover->value,
                    'hours'            => $carryHours,
                    'balance_before'   => 0,
                    'balance_after'    => $carryHours,
                    'reference_type'   => 'cycle_advance',
                    'reference_id'     => $closedCycle->id,
                    'description'      => "Carry-over from {$closedCycle->name}",
                    'performed_by'     => null,
                    'created_at'       => now(),
                ]);
            }
        }
    }
}
