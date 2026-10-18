<?php

namespace App\Modules\HR\Leave\Services;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveManualAllocationBatch;
use App\Modules\HR\Leave\Models\LeaveManualAllocationBatchItem;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Manual leave allocation: work out who is affected and by how much, apply it as
 * a recorded batch, and reverse a batch when it was wrong.
 *
 * Applying always goes through here rather than calling LeaveBalanceService
 * directly, so every manual adjustment leaves a batch behind. Without that there
 * is nothing to list and nothing to reverse — undoing a bad department-wide
 * allocation would mean correcting each employee by hand.
 */
class LeaveManualAllocationService
{
    /** Hours in a standard working day, matching the rest of the leave module. */
    private const HOURS_PER_DAY = 8.0;

    public function __construct(private readonly LeaveBalanceService $balances) {}

    // ── Preview ───────────────────────────────────────────────────────────────

    /**
     * What this allocation would do, per employee, without changing anything.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function preview(string $scopeType, ?int $scopeId, ?int $employeeId, int $leaveTypeId, string $action, float $days): array
    {
        $employees = $this->resolveEmployees($scopeType, $scopeId, $employeeId);
        $hours     = $this->signedHours($action, $days);
        $leaveType = LeaveType::find($leaveTypeId);

        $rows = [];
        $goesNegative = 0;

        foreach ($employees as $employee) {
            $before = (float) $this->balances->getBalance($employee->id, $leaveTypeId);
            $after  = $before + $hours;

            if ($after < 0) {
                $goesNegative++;
            }

            $rows[] = [
                'employee_id'     => $employee->id,
                'employee_name'   => trim($employee->first_name . ' ' . $employee->surname),
                'employee_number' => $employee->employee_number,
                'balance_before'  => round($before, 2),
                'change_hours'    => round($hours, 2),
                'balance_after'   => round($after, 2),
                'days_before'     => round($before / self::HOURS_PER_DAY, 2),
                'days_change'     => round($hours / self::HOURS_PER_DAY, 2),
                'days_after'      => round($after / self::HOURS_PER_DAY, 2),
                'goes_negative'   => $after < 0,
            ];
        }

        return [
            'rows'   => $rows,
            'totals' => [
                'employees'      => count($rows),
                'action'         => $action,
                'days_each'      => $days,
                'hours_each'     => abs($hours),
                'total_hours'    => round(abs($hours) * count($rows), 2),
                'total_days'     => round($days * count($rows), 2),
                'goes_negative'  => $goesNegative,
                'leave_type'     => $leaveType?->name,
                'scope_name'     => $this->scopeName($scopeType, $scopeId, $employees),
            ],
        ];
    }

    // ── Apply ─────────────────────────────────────────────────────────────────

    /**
     * Apply the allocation and record it as a reversible batch. Balances are read
     * again here rather than trusting the preview, so a balance that moved between
     * preview and confirm is recorded accurately.
     */
    public function apply(string $scopeType, ?int $scopeId, ?int $employeeId, int $leaveTypeId, string $action, float $days, string $reason): LeaveManualAllocationBatch
    {
        $employees = $this->resolveEmployees($scopeType, $scopeId, $employeeId);

        if ($employees->isEmpty()) {
            throw new \RuntimeException('No active employees found for the selected scope.');
        }

        $hours     = $this->signedHours($action, $days);
        $leaveType = LeaveType::find($leaveTypeId);
        $user      = Auth::user();

        return DB::transaction(function () use ($employees, $hours, $days, $action, $leaveTypeId, $leaveType, $reason, $scopeType, $scopeId, $user) {
            $batch = LeaveManualAllocationBatch::create([
                'scope_type'         => $scopeType,
                'scope_id'           => $scopeId,
                'scope_name'         => $this->scopeName($scopeType, $scopeId, $employees),
                'leave_type_id'      => $leaveTypeId,
                'leave_type_name'    => $leaveType?->name,
                'action'             => $action,
                'days'               => $days,
                'hours_per_employee' => abs($hours),
                'reason'             => $reason,
                'status'             => LeaveManualAllocationBatch::STATUS_APPLIED,
                'performed_by'       => $user?->id,
                'performed_by_name'  => $this->userName($user),
            ]);

            $applied = 0;

            foreach ($employees as $employee) {
                $before = (float) $this->balances->getBalance($employee->id, $leaveTypeId);

                $this->balances->manualAdjust(
                    hrPersonId:  $employee->id,
                    leaveTypeId: $leaveTypeId,
                    hours:       $hours,
                    reason:      $reason,
                );

                LeaveManualAllocationBatchItem::create([
                    'batch_id'        => $batch->id,
                    'employee_id'     => $employee->id,
                    'employee_name'   => trim($employee->first_name . ' ' . $employee->surname),
                    'employee_number' => $employee->employee_number,
                    'leave_type_id'   => $leaveTypeId,
                    'hours'           => $hours,
                    'balance_before'  => round($before, 2),
                    'balance_after'   => round($before + $hours, 2),
                ]);

                $applied++;
            }

            $batch->forceFill([
                'total_employees' => $applied,
                'total_hours'     => round(abs($hours) * $applied, 2),
            ])->save();

            return $batch;
        });
    }

    // ── Reverse ───────────────────────────────────────────────────────────────

    /**
     * Undo a batch by posting the opposite adjustment for each item. The original
     * entries are left in the ledger — reversing is an additional, auditable
     * movement rather than a deletion.
     */
    public function reverse(LeaveManualAllocationBatch $batch, ?string $reason = null): int
    {
        if ($batch->isReversed()) {
            throw new \RuntimeException('This allocation has already been reversed.');
        }

        $user = Auth::user();

        return DB::transaction(function () use ($batch, $reason, $user) {
            $reversed = 0;

            foreach ($batch->items()->where('is_reversed', false)->get() as $item) {
                $this->balances->manualAdjust(
                    hrPersonId:  $item->employee_id,
                    leaveTypeId: $item->leave_type_id,
                    hours:       -1 * (float) $item->hours,
                    reason:      'Reversal of manual allocation batch #' . $batch->id
                        . ($reason ? ' — ' . $reason : ''),
                );

                $item->forceFill(['is_reversed' => true])->save();
                $reversed++;
            }

            $batch->forceFill([
                'status'            => LeaveManualAllocationBatch::STATUS_REVERSED,
                'reversed_by'       => $user?->id,
                'reversed_by_name'  => $this->userName($user),
                'reversed_at'       => now(),
                'reversal_reason'   => $reason,
            ])->save();

            return $reversed;
        });
    }

    // ── Scope resolution ──────────────────────────────────────────────────────

    /** @return Collection<int, HRPerson> */
    public function resolveEmployees(string $scopeType, ?int $scopeId, ?int $employeeId): Collection
    {
        if ($scopeType === 'single') {
            return HRPerson::where('id', $employeeId)
                ->where('status', 1)
                ->get(['id', 'first_name', 'surname', 'employee_number']);
        }

        $unitIds = $this->childUnitIds((int) $scopeId);

        return HRPerson::where('status', 1)
            ->where(function ($q) use ($unitIds) {
                $q->whereIn('division_level_1', $unitIds)
                    ->orWhereIn('division_level_2', $unitIds)
                    ->orWhereIn('division_level_3', $unitIds)
                    ->orWhereIn('division_level_4', $unitIds)
                    ->orWhereIn('division_level_5', $unitIds)
                    ->orWhereIn('user_id', function ($sub) use ($unitIds) {
                        $sub->select('user_id')
                            ->from('company_unit_user_pivots')
                            ->whereIn('company_id', $unitIds);
                    });
            })
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'surname', 'employee_number'])
            ->unique('id')
            ->values();
    }

    /** A unit and every unit beneath it. */
    private function childUnitIds(int $parentId): array
    {
        $ids = [$parentId];

        foreach (CompanyUnit::where('parent_id', $parentId)->pluck('id') as $childId) {
            $ids = array_merge($ids, $this->childUnitIds((int) $childId));
        }

        return array_values(array_unique($ids));
    }

    private function signedHours(string $action, float $days): float
    {
        $hours = $days * self::HOURS_PER_DAY;

        return $action === 'add' ? $hours : -$hours;
    }

    private function scopeName(string $scopeType, ?int $scopeId, Collection $employees): string
    {
        if ($scopeType === 'single') {
            $first = $employees->first();

            return $first ? trim($first->first_name . ' ' . $first->surname) : 'Employee';
        }

        return CompanyUnit::find($scopeId)?->name ?? 'Unknown unit';
    }

    private function userName($user): string
    {
        if (! $user) {
            return 'System';
        }

        $person = $user->person;

        return $person
            ? trim($person->first_name . ' ' . $person->surname)
            : (string) $user->email;
    }
}
