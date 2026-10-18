<?php

namespace App\Modules\HR\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollAnomalyRule extends Model
{
    use LogsActivity;

    protected $table = 'payroll_anomaly_rules';

    protected $fillable = [
        'name',
        'description',
        'anomaly_type',
        'threshold_type',
        'threshold_value',
        'severity',
        'action_on_breach',
        'applies_to',
        'active',
    ];

    protected $casts = [
        'threshold_value' => 'decimal:4',
        'active'          => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Payroll')
            ->logFillable();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->causer_id   = auth()->id();
        $activity->causer_type = auth()->user() ? get_class(auth()->user()) : null;

        $properties = $activity->properties->toArray();
        $properties['ip_address'] = request()->ip();
        $properties['user_agent'] = request()->userAgent();
        $activity->properties = collect($properties);
    }
}
