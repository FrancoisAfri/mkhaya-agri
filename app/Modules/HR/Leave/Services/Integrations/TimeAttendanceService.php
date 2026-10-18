<?php

namespace App\Modules\HR\Leave\Services\Integrations;

use App\Modules\HR\Leave\Contracts\TimeAttendanceServiceContract;
use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Support\Facades\Log;

class TimeAttendanceService implements TimeAttendanceServiceContract
{
    public function pushApprovedLeave(LeaveRequest $request): bool
    {
        try {
            // Integration point: push to T&A system
            // Implementation depends on the T&A platform (e.g., Kronos, TimeSoft, custom)
            Log::info('T&A: Pushing approved leave', [
                'request_number' => $request->request_number,
                'employee_id'    => $request->employee_id,
                'start_date'     => $request->start_date,
                'end_date'       => $request->end_date,
                'hours'          => $request->working_hours,
            ]);

            $request->update(['ta_synced_at' => now()]);
            return true;
        } catch (\Throwable $e) {
            Log::error('T&A sync failed', ['error' => $e->getMessage(), 'request_id' => $request->id]);
            return false;
        }
    }

    public function reverseLeave(LeaveRequest $request): bool
    {
        try {
            Log::info('T&A: Reversing leave', ['request_number' => $request->request_number]);
            return true;
        } catch (\Throwable $e) {
            Log::error('T&A reverse failed', ['error' => $e->getMessage(), 'request_id' => $request->id]);
            return false;
        }
    }

    public function pushOvertime(LeaveRequest $request): bool
    {
        try {
            Log::info('T&A: Pushing overtime', ['request_number' => $request->request_number]);
            return true;
        } catch (\Throwable $e) {
            Log::error('T&A overtime push failed', ['error' => $e->getMessage(), 'request_id' => $request->id]);
            return false;
        }
    }
}
