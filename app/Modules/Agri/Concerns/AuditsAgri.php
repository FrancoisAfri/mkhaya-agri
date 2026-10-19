<?php

namespace App\Modules\Agri\Concerns;

use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The audit trail for farm records (spec §3.3 audit_events, FR-SEC-006), on the platform's own
 * activity log rather than a second audit table.
 *
 * Every create, change and delete is logged with who did it (or the system process, when nobody
 * did), the organisation and farm the record belongs to, and the values before and after. The
 * organisation is taken from the record, never guessed from the session.
 */
trait AuditsAgri
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('agri')
            ->logAll()
            ->logExcept(['updated_at', 'created_at', 'data'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (isset($this->company_id)) {
            $activity->company_id = $this->company_id;
        }

        $farmId = $this->farm_id ?? ($this->getTable() === 'farms' ? $this->id : null);
        $actor = Auth::check() ? null : (app()->runningInConsole() ? 'system:'.($_SERVER['argv'][1] ?? 'console') : 'system');

        $activity->properties = ($activity->properties ?? collect())->merge(array_filter([
            'farm_id' => $farmId,
            'actor' => $actor,
            'label' => $this->code ?? $this->name ?? null,
        ], fn ($v) => $v !== null));
    }
}
