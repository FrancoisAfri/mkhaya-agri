<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One work session: a clock-in and its clock-out (several a day are several sessions), measured
 * against the roster and the worker's rules, approved, and costed to the job it was for. Approved
 * sessions make the day's attendance record that payroll reads.
 */
class Timesheet extends SitedModel
{
    public const OPEN = 'open';
    public const COMPLETE = 'complete';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'attendance_timesheets';

    protected $casts = ['work_date' => 'date', 'scheduled_start' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'scheduled_end' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'clock_in_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class,
        'clock_out_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'minutes_by_date' => 'array', 'flags' => 'array', 'approved_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'cost_amount' => 'float'];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(Sites::model('work_order'), 'work_order_id');
    }
}
