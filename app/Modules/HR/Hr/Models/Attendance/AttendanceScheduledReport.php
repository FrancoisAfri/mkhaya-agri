<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AttendanceScheduledReport extends Model
{
    protected $table = 'attendance_scheduled_reports';

    protected $fillable = [
        'name', 'report_key', 'cadence', 'run_time',
        'day_of_week', 'day_of_month', 'format',
        'recipients', 'active', 'next_run_at', 'created_by',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'last_run_at'  => 'datetime',
        'next_run_at'  => 'datetime',
        'day_of_week'  => 'integer',
        'day_of_month' => 'integer',
    ];

    public function computeNextRun(): Carbon
    {
        [$h, $m] = explode(':', $this->run_time ?? '07:00');
        $now = Carbon::now();

        return match ($this->cadence) {
            'weekly'  => $this->nextWeeklyRun((int) $h, (int) $m, $now),
            'monthly' => $this->nextMonthlyRun((int) $h, (int) $m, $now),
            default   => $now->copy()->addDay()->setTime((int) $h, (int) $m, 0),
        };
    }

    private function nextWeeklyRun(int $h, int $m, Carbon $now): Carbon
    {
        $target    = $this->day_of_week ?? 1;
        $candidate = $now->copy()->setTime($h, $m, 0);
        while ($candidate->isoWeekday() !== $target || $candidate->lte($now)) {
            $candidate->addDay();
        }
        return $candidate;
    }

    private function nextMonthlyRun(int $h, int $m, Carbon $now): Carbon
    {
        $day       = max(1, min($this->day_of_month ?? 1, 28));
        $candidate = $now->copy()->day($day)->setTime($h, $m, 0);
        if ($candidate->lte($now)) {
            $candidate->addMonthNoOverflow();
        }
        return $candidate;
    }
}
