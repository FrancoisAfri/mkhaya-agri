<?php

namespace App\Modules\HR\Leave\Repositories;

use App\Modules\HR\Leave\Contracts\LeaveBalanceRepositoryInterface;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentLeaveBalanceRepository implements LeaveBalanceRepositoryInterface
{
    public function currentBalance(int $hrPersonId, int $leaveTypeId, ?int $cycleId = null): float
    {
        $q = LeaveBalanceTransaction::where('employee_id', $hrPersonId)
            ->where('leave_type_id', $leaveTypeId);

        if ($cycleId) {
            $q->where('leave_cycle_id', $cycleId);
        }

        $last = $q->latest('id')->first();

        return (float) ($last?->balance_after ?? 0);
    }

    public function allBalances(int $hrPersonId, ?int $cycleId = null): Collection
    {
        return LeaveType::where('status', 1)
            ->get()
            ->map(function (LeaveType $lt) use ($hrPersonId, $cycleId) {
                $balance = $this->currentBalance($hrPersonId, $lt->id, $cycleId);
                return [
                    'leave_type_id'   => $lt->id,
                    'leave_type_name' => $lt->name,
                    'balance_hours'   => $balance,
                    'balance_days'    => round($balance / 8, 2),
                ];
            });
    }

    public function credit(int $hrPersonId, int $leaveTypeId, float $hours, string $type, array $meta = []): void
    {
        $this->record($hrPersonId, $leaveTypeId, abs($hours), $type, $meta);
    }

    public function debit(int $hrPersonId, int $leaveTypeId, float $hours, string $type, array $meta = []): void
    {
        $this->record($hrPersonId, $leaveTypeId, -abs($hours), $type, $meta);
    }

    public function transactions(int $hrPersonId, int $leaveTypeId, ?int $cycleId = null): Collection
    {
        $q = LeaveBalanceTransaction::where('employee_id', $hrPersonId)
            ->where('leave_type_id', $leaveTypeId)
            ->latest('id');

        if ($cycleId) {
            $q->where('leave_cycle_id', $cycleId);
        }

        return $q->get();
    }

    private function record(int $hrPersonId, int $leaveTypeId, float $hours, string $type, array $meta): void
    {
        DB::transaction(function () use ($hrPersonId, $leaveTypeId, $hours, $type, $meta) {
            $before = $this->currentBalance($hrPersonId, $leaveTypeId, $meta['leave_cycle_id'] ?? null);
            $after  = $before + $hours;

            LeaveBalanceTransaction::create([
                'employee_id'    => $hrPersonId,
                'leave_type_id'  => $leaveTypeId,
                'leave_cycle_id' => $meta['leave_cycle_id'] ?? null,
                'transaction_type' => $type,
                'hours'          => $hours,
                'balance_before' => $before,
                'balance_after'  => $after,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id'   => $meta['reference_id']   ?? null,
                'description'    => $meta['description']    ?? null,
                'performed_by'   => $meta['performed_by']   ?? null,
                'created_at'     => now(),
            ]);
        });
    }
}
