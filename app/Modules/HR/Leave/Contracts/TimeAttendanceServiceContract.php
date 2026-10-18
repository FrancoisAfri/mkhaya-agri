<?php

namespace App\Modules\HR\Leave\Contracts;

use App\Modules\HR\Leave\Models\LeaveRequest;

interface TimeAttendanceServiceContract
{
    public function pushApprovedLeave(LeaveRequest $request): bool;
    public function reverseLeave(LeaveRequest $request): bool;
    public function pushOvertime(LeaveRequest $request): bool;
}
