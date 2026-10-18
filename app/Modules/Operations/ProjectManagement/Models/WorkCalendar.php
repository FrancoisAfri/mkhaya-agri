<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Working-time definition: which hours count as work, in which timezone.
 *
 * The scheduling engine never reads this model — a service loads it into plain value objects and
 * passes them in (PM-ADR-005). That is what keeps date arithmetic unit-testable without a database
 * and lets the same engine run against a hypothetical calendar for what-if analysis.
 *
 * work_week is local wall-clock time in `timezone`:
 *   {"mon":[["07:00","16:00"]], "fri":[["07:00","13:00"]], "sat":[], "sun":[]}
 * Multiple windows per day are allowed (split shifts).
 */
class WorkCalendar extends Model
{
    use AuditsChanges, BelongsToCompany, HasFactory, HasPublicUuid, LogsActivity, SoftDeletes;

    /** Factories live under Database\Factories\ProjectManagement, not the default path. */
    protected static function newFactory(): \Database\Factories\ProjectManagement\WorkCalendarFactory
    {
        return \Database\Factories\ProjectManagement\WorkCalendarFactory::new();
    }

    protected $table = 'pm_calendars';

    protected $fillable = ['uuid', 'company_id', 'name', 'timezone', 'work_week', 'is_default', 'created_by'];

    protected $casts = [
        'work_week'  => 'array',
        'is_default' => 'boolean',
    ];

    /** Mon–Fri 08:00–17:00 — the default for a tenant that has not configured one. */
    public const STANDARD_WEEK = [
        'mon' => [['08:00', '17:00']],
        'tue' => [['08:00', '17:00']],
        'wed' => [['08:00', '17:00']],
        'thu' => [['08:00', '17:00']],
        'fri' => [['08:00', '17:00']],
        'sat' => [],
        'sun' => [],
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(CalendarException::class, 'calendar_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'calendar_id');
    }
}
