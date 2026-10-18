<?php

namespace App\Modules\HR\Leave\Contracts;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface LeaveRequestRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator;
    public function find(int $id): ?LeaveRequest;
    public function findByUuid(string $uuid): ?LeaveRequest;
    public function forEmployee(int $hrPersonId, array $filters = []): Collection;
    public function pendingForApprover(int $userId): Collection;
    public function create(array $data): LeaveRequest;
    public function update(LeaveRequest $request, array $data): LeaveRequest;
    public function overlapping(int $hrPersonId, string $start, string $end, ?int $excludeId = null): Collection;
}
