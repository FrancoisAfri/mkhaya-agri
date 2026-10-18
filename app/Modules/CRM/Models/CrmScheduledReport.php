<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmScheduledReport extends Model
{
    protected $table = 'crm_scheduled_reports';

    protected $fillable = [
        'name', 'report_key', 'cadence', 'run_time', 'day_of_week', 'day_of_month',
        'format', 'recipients', 'active', 'last_run_at', 'next_run_at', 'created_by',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'last_run_at'  => 'datetime',
        'next_run_at'  => 'datetime',
        'day_of_week'  => 'integer',
        'day_of_month' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<int,string> trimmed recipient emails */
    public function recipientList(): array
    {
        return collect(explode(',', (string) $this->recipients))
            ->map(fn ($e) => trim($e))->filter()->values()->all();
    }

    /**
     * Owner ids this report should cover — the creator's visibility scope, so a
     * manager's scheduled report carries their team and an exec's carries all.
     *
     * @return array<int,int>|null
     */
    public function ownerScope(): ?array
    {
        $creator = $this->createdBy;
        if (! $creator) {
            return null;
        }
        return app(\App\Modules\CRM\Services\CrmVisibilityService::class)->visibleOwnerIds($creator);
    }

    public function computeNextRun(): Carbon
    {
        [$h, $m] = array_pad(explode(':', $this->run_time ?? '07:00'), 2, '0');
        $h = (int) $h; $m = (int) $m;
        $now = Carbon::now();

        return match ($this->cadence) {
            'weekly'  => $this->nextWeeklyRun($h, $m, $now),
            'monthly' => $this->nextMonthlyRun($h, $m, $now),
            default   => $this->nextDailyRun($h, $m, $now),
        };
    }

    private function nextDailyRun(int $h, int $m, Carbon $now): Carbon
    {
        $candidate = $now->copy()->setTime($h, $m, 0);
        return $candidate->lte($now) ? $candidate->addDay() : $candidate;
    }

    private function nextWeeklyRun(int $h, int $m, Carbon $now): Carbon
    {
        $target = $this->day_of_week ?: 1;
        $candidate = $now->copy()->setTime($h, $m, 0);
        while ($candidate->isoWeekday() !== $target || $candidate->lte($now)) {
            $candidate->addDay();
        }
        return $candidate;
    }

    private function nextMonthlyRun(int $h, int $m, Carbon $now): Carbon
    {
        $day = max(1, min($this->day_of_month ?: 1, 28));
        $candidate = $now->copy()->day($day)->setTime($h, $m, 0);
        if ($candidate->lte($now)) {
            $candidate->addMonthNoOverflow();
        }
        return $candidate;
    }
}
