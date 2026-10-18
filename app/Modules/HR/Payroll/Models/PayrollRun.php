<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollRun extends Model
{
    use SoftDeletes, LogsActivity;

    // EMP201s, tax certificates and payroll reports belong to the run that produced them.
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    protected $table = 'payroll_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'status'              => PayrollRunStatus::class,
        'period_start'        => 'date',
        'period_end'          => 'date',
        'released_at'         => 'datetime',
        'ai_anomaly_summary'  => 'array',
        'total_gross'         => 'decimal:2',
        'total_deductions'    => 'decimal:2',
        'total_eti'           => 'decimal:2',
        'total_net'           => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Payroll')
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(PayrollAnomaly::class, 'run_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PayrollApproval::class, 'run_id');
    }

    public function riskScores(): HasMany
    {
        return $this->hasMany(PayrollRiskScore::class, 'run_id');
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(PayrollChangeLog::class, 'run_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(PayrollAuditLog::class, 'run_id');
    }
}
