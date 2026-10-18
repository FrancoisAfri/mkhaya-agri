<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollChangeRisk extends Model
{
    use LogsActivity;

    protected $table = 'payroll_change_risks';

    protected $fillable = [
        'change_log_id',
        'risk_type',
        'risk_description',
        'severity',
        'flagged_by',
        'reviewed_by',
        'reviewed_at',
        'status',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
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

    public function changeLog(): BelongsTo
    {
        return $this->belongsTo(PayrollChangeLog::class, 'change_log_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
