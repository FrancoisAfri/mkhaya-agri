<?php

namespace App\Shared\Procurement\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProcurementScheduledReport extends Model
{
    protected $table = 'procurement_scheduled_reports';

    protected $fillable = [
        'module', 'name', 'report_key', 'cadence', 'run_time',
        'day_of_week', 'day_of_month', 'format', 'recipients', 'active',
        'last_run_at', 'next_run_at', 'created_by',
    ];

    protected $casts = [
        'active'      => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function scopeModule(Builder $q, string $module): Builder
    {
        return $q->where('module', $module);
    }

    /** Compute the next run timestamp from cadence + run_time (+ day). */
    public function computeNextRun(): Carbon
    {
        [$h, $m] = array_pad(explode(':', $this->run_time ?: '07:00'), 2, '0');
        $h = (int) $h;
        $m = (int) $m;
        $now = Carbon::now();

        return match ($this->cadence) {
            'weekly'  => $this->nextWeekly($h, $m, $now),
            'monthly' => $this->nextMonthly($h, $m, $now),
            default   => $now->copy()->addDay()->setTime($h, $m, 0),
        };
    }

    private function nextWeekly(int $h, int $m, Carbon $now): Carbon
    {
        $target = $this->day_of_week ?: 1;
        $c = $now->copy()->setTime($h, $m, 0);
        while ($c->isoWeekday() !== $target || $c->lte($now)) {
            $c->addDay();
        }

        return $c;
    }

    private function nextMonthly(int $h, int $m, Carbon $now): Carbon
    {
        $day = max(1, min($this->day_of_month ?: 1, 28));
        $c = $now->copy()->day($day)->setTime($h, $m, 0);
        if ($c->lte($now)) {
            $c->addMonthNoOverflow();
        }

        return $c;
    }
}
