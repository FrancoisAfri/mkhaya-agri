<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollAnomaly extends Model
{
    use SoftDeletes;
    use LogsActivity;

    protected $table = 'payroll_anomalies';

    protected $fillable = [
        'uuid',
        'run_id',
        'item_id',
        'employee_id',
        'anomaly_type',
        'description',
        'severity',
        'status',
        'detected_at',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
        'metadata',
    ];

    protected $casts = [
        'detected_at'  => 'datetime',
        'resolved_at'  => 'datetime',
        'metadata'     => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Payroll')
            ->logOnly(['status', 'anomaly_type', 'severity']);
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

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'item_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
