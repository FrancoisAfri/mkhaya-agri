<?php

namespace App\Modules\HR\Leave\Jobs;

use App\Modules\HR\Leave\Contracts\TimeAttendanceServiceContract;
use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncLeaveToTimeAttendanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 30;
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public readonly LeaveRequest $request) {}

    public function handle(TimeAttendanceServiceContract $ta): void
    {
        $success = $this->request->is_overtime
            ? $ta->pushOvertime($this->request)
            : $ta->pushApprovedLeave($this->request);

        if (! $success) {
            $this->release(60);
        }
    }
}
