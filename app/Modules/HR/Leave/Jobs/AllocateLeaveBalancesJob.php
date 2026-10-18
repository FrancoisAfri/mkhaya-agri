<?php

namespace App\Modules\HR\Leave\Jobs;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Repositories\EloquentLeaveBalanceRepository;
use App\Modules\HR\Leave\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AllocateLeaveBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /**
     * @param string $mode 'monthly' | 'yearly' | 'prorated'
     */
    public function __construct(private readonly string $mode = 'monthly') {}

    public function handle(EloquentLeaveBalanceRepository $balanceRepo, LeaveBalanceService $balanceService): void
    {
        $cycle = LeaveCycle::active();

        if (! $cycle) {
            Log::warning('AllocateLeaveBalancesJob: No active leave cycle found.');
            return;
        }

        $employees = DB::table('hr_people')
            ->where('status', 1)
            ->whereNotNull('leave_profile')
            ->where('leave_profile', '>', 0)
            ->select('id', 'leave_profile', 'date_joined')
            ->get();

        $processed = 0;

        foreach ($employees as $emp) {
            match ($this->mode) {
                'yearly'   => $this->allocateYearly($emp, $cycle, $balanceRepo),
                'prorated' => $this->allocateProrated($emp, $cycle, $balanceService),
                default    => $this->allocateMonthly($emp, $cycle, $balanceRepo),
            };
            $processed++;
        }

        Log::info("AllocateLeaveBalancesJob ({$this->mode}): Processed {$processed} employees for cycle {$cycle->name}.");
    }

    private function allocateMonthly(object $emp, LeaveCycle $cycle, EloquentLeaveBalanceRepository $repo): void
    {
        $allocations = LeaveProfileAllocation::where('leave_profile_id', $emp->leave_profile)
            ->where('active', true)
            ->whereNotNull('accrual_rate_hours_per_month')
            ->get();

        foreach ($allocations as $allocation) {
            try {
                $repo->credit(
                    hrPersonId:  $emp->id,
                    leaveTypeId: $allocation->leave_type_id,
                    hours:       (float) $allocation->accrual_rate_hours_per_month,
                    type:        LeaveTransactionType::Allocation->value,
                    meta: [
                        'leave_cycle_id' => $cycle->id,
                        'description'    => "Monthly accrual allocation — {$cycle->name}",
                        'performed_by'   => null,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('Monthly allocation failed', ['employee_id' => $emp->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function allocateYearly(object $emp, LeaveCycle $cycle, EloquentLeaveBalanceRepository $repo): void
    {
        // Yearly: credit default_hours for types that use a fixed annual grant (no monthly accrual)
        $allocations = LeaveProfileAllocation::where('leave_profile_id', $emp->leave_profile)
            ->where('active', true)
            ->whereNull('accrual_rate_hours_per_month')
            ->whereNotNull('default_hours')
            ->where('default_hours', '>', 0)
            ->get();

        foreach ($allocations as $allocation) {
            // Skip if already allocated in this cycle
            $alreadyAllocated = LeaveBalanceTransaction::where('employee_id', $emp->id)
                ->where('leave_type_id', $allocation->leave_type_id)
                ->where('leave_cycle_id', $cycle->id)
                ->where('transaction_type', LeaveTransactionType::Allocation->value)
                ->exists();

            if ($alreadyAllocated) {
                continue;
            }

            try {
                $repo->credit(
                    hrPersonId:  $emp->id,
                    leaveTypeId: $allocation->leave_type_id,
                    hours:       (float) $allocation->default_hours,
                    type:        LeaveTransactionType::Allocation->value,
                    meta: [
                        'leave_cycle_id' => $cycle->id,
                        'description'    => "Annual allocation — {$cycle->name}",
                        'performed_by'   => null,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('Yearly allocation failed', ['employee_id' => $emp->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function allocateProrated(object $emp, LeaveCycle $cycle, LeaveBalanceService $service): void
    {
        $dateJoined = $emp->date_joined;
        if (! $dateJoined) {
            return;
        }

        $joinDate   = is_numeric($dateJoined) ? Carbon::createFromTimestamp($dateJoined) : Carbon::parse($dateJoined);
        $cycleStart = $cycle->start_date instanceof Carbon ? $cycle->start_date : Carbon::parse($cycle->start_date);
        $cycleEnd   = $cycle->end_date instanceof Carbon   ? $cycle->end_date   : Carbon::parse($cycle->end_date);

        // Only for employees who joined within the current cycle
        if ($joinDate->lte($cycleStart) || $joinDate->gte($cycleEnd)) {
            return;
        }

        $allocations = LeaveProfileAllocation::where('leave_profile_id', $emp->leave_profile)
            ->where('active', true)
            ->get();

        $hrPerson = HRPerson::find($emp->id);
        if (! $hrPerson) {
            return;
        }

        foreach ($allocations as $allocation) {
            try {
                $service->allocateProrated($hrPerson, $allocation, $cycle);
            } catch (\Throwable $e) {
                Log::error('Prorated allocation failed', ['employee_id' => $emp->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
