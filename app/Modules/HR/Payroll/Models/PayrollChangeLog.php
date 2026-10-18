<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollChangeLog extends Model
{
    use LogsActivity;

    protected $table = 'payroll_change_logs';

    protected $fillable = [
        'run_id',
        'payslip_id',
        'employee_id',
        'field_changed',
        'old_value',
        'new_value',
        'change_type',
        'changed_by',
        'change_reason',
        'risk_score',
        'risk_level',
        'ip_address',
        'changed_at',
    ];

    protected $casts = [
        'risk_score' => 'integer',
        'changed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Payroll')
            ->logOnly(['field_changed', 'old_value', 'new_value', 'risk_level']);
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
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function changeRisks(): HasMany
    {
        return $this->hasMany(PayrollChangeRisk::class, 'change_log_id');
    }
}
