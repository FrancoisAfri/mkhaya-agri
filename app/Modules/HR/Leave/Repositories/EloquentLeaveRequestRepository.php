<?php

namespace App\Modules\HR\Leave\Repositories;

use App\Modules\HR\Leave\Contracts\LeaveRequestRepositoryInterface;
use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentLeaveRequestRepository implements LeaveRequestRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $q = LeaveRequest::with(['employee', 'leaveType', 'approvalLevels'])
            ->latest();

        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['leave_type_id'])) {
            $q->where('leave_type_id', $filters['leave_type_id']);
        }
        if (! empty($filters['from'])) {
            $q->where('start_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('end_date', '<=', $filters['to']);
        }

        return $q->paginate($perPage);
    }

    public function find(int $id): ?LeaveRequest
    {
        return LeaveRequest::with([
            'employee', 'leaveType', 'cycle',
            'approvalLevels', 'documents',
        ])->find($id);
    }

    public function findByUuid(string $uuid): ?LeaveRequest
    {
        return LeaveRequest::with([
            'employee', 'leaveType', 'cycle',
            'approvalLevels', 'documents',
        ])->where('uuid', $uuid)->first();
    }

    public function forEmployee(int $hrPersonId, array $filters = []): Collection
    {
        $q = LeaveRequest::with(['leaveType', 'approvalLevels'])
            ->where('employee_id', $hrPersonId)
            ->latest();

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->get();
    }

    public function pendingForApprover(int $userId): Collection
    {
        return LeaveRequest::with(['employee', 'leaveType'])
            ->forApprover($userId)
            ->oldest('submitted_at')
            ->get();
    }

    public function create(array $data): LeaveRequest
    {
        return LeaveRequest::create($data);
    }

    public function update(LeaveRequest $request, array $data): LeaveRequest
    {
        $request->update($data);
        return $request->fresh(['employee', 'leaveType', 'approvalLevels']);
    }

    public function overlapping(int $hrPersonId, string $start, string $end, ?int $excludeId = null): Collection
    {
        $q = LeaveRequest::where('employee_id', $hrPersonId)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start);

        if ($excludeId) {
            $q->where('id', '!=', $excludeId);
        }

        return $q->get();
    }
}
