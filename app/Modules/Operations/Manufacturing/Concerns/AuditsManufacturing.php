<?php

namespace App\Modules\Operations\Manufacturing\Concerns;

use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The audit trail for manufacturing records (MFG-023), on the platform's own activity log.
 *
 * Every create, change and delete of a model using this is logged with the person who did it -- or
 * the system process, when nobody did -- the company and factory the record belongs to, the record
 * itself, and the values before and after. The company is taken from the record, never guessed
 * from the session: an entry always belongs to the company that owns what changed.
 */
trait AuditsManufacturing
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('manufacturing')
            ->logFillable()
            ->logExcept(['updated_at', 'created_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (isset($this->company_id)) {
            $activity->company_id = $this->company_id;
        }
        $props = $activity->properties ?? collect();
        $factory = $this->factory_id ?? null;
        if ($factory === null && method_exists($this, 'order') && isset($this->order_id)) {
            $factory = \App\Modules\Operations\Manufacturing\Models\ProductionOrder::withoutGlobalScopes()->whereKey($this->order_id)->value('factory_id');
        }
        $actor = Auth::check() ? null : (app()->runningInConsole() ? 'system:'.($_SERVER['argv'][1] ?? 'console') : 'system');
        $activity->properties = $props->merge(array_filter(['factory_id' => $factory, 'actor' => $actor,
            'label' => $this->number ?? $this->code ?? $this->name ?? null], fn ($v) => $v !== null));
    }
}
