<?php

namespace App\Modules\HR\Leave\Contracts;

use App\Modules\HR\Leave\Models\LeavePayoutRecord;
use App\Modules\HR\Leave\Models\LeaveRequest;

interface PayrollServiceContract
{
    public function syncLeaveApproval(LeaveRequest $request): bool;
    public function syncPayout(LeavePayoutRecord $record): bool;
    public function flag(LeaveRequest $request, string $reason): bool;
}
