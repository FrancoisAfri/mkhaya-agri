<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A single-date override on a calendar: a public holiday or shutdown (is_working = false), or an
 * unusual working day such as a Saturday catch-up shift (is_working = true with explicit windows).
 *
 * Public holidays are a platform data source (`public_holidays`); importing them into a project
 * calendar is a deliberate, per-tenant action rather than an implicit join, because projects
 * routinely work through holidays and the schedule must reflect what was actually agreed.
 */
class CalendarException extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_calendar_exceptions';

    protected $fillable = [
        'uuid', 'company_id', 'calendar_id', 'exception_date', 'is_working', 'windows', 'reason',
    ];

    protected $casts = [
        'exception_date' => 'date',
        'is_working'     => 'boolean',
        'windows'        => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class, 'calendar_id');
    }
}
