<?php

namespace App\Modules\HR\Leave\Contracts;

use Illuminate\Support\Collection;

interface LeaveBalanceRepositoryInterface
{
    public function currentBalance(int $hrPersonId, int $leaveTypeId, ?int $cycleId = null): float;
    public function allBalances(int $hrPersonId, ?int $cycleId = null): Collection;
    public function credit(int $hrPersonId, int $leaveTypeId, float $hours, string $type, array $meta = []): void;
    public function debit(int $hrPersonId, int $leaveTypeId, float $hours, string $type, array $meta = []): void;
    public function transactions(int $hrPersonId, int $leaveTypeId, ?int $cycleId = null): Collection;
}
