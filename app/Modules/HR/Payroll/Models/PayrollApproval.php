<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollApproval extends Model
{
    use LogsActivity;

    protected $table = 'payroll_approvals';

    protected $fillable = [
        'uuid',
        'run_id',
        'current_level',
        'status',
        'submitted_by',
        'due_date',
        'high_risk',
        'auto_escalated',
        'metadata',
    ];

    protected $casts = [
        'due_date'       => 'date',
        'high_risk'      => 'boolean',
        'auto_escalated' => 'boolean',
        'metadata'       => 'array',
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
            ->logOnly(['status', 'current_level']);
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

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(PayrollApprovalStep::class, 'approval_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PayrollApprovalComment::class, 'approval_id');
    }
}
