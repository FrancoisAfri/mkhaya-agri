<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Modules\HR\Payroll\Enums\PayslipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payslip extends Model
{
    use StoresFileBytes;

    use SoftDeletes, LogsActivity;
    protected $table = 'payslips';

    protected $guarded = ['id'];

    protected $casts = [
        'pro_rata' => 'array',
        'status'           => PayslipStatus::class,
        'period_start'     => 'date',
        'period_end'       => 'date',
        'issued_at'        => 'datetime',
        'viewed_at'        => 'datetime',
        'basic_salary'     => 'decimal:2',
        'gross_pay'        => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay'          => 'decimal:2',
        'paye'             => 'decimal:2',
        'uif'              => 'decimal:2',
        'pension'          => 'decimal:2',
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

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function earningsLines(): HasMany
    {
        return $this->hasMany(PayrollEarningsLine::class);
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(PayrollAnomaly::class, 'item_id');
    }

    public function riskScores(): HasMany
    {
        return $this->hasMany(PayrollRiskScore::class, 'payslip_id');
    }

    protected function fileBlobColumn(): ?string
    {
        return 'pdf_data';
    }

    protected function fileFolder(): string
    {
        return 'payroll/payslips';
    }
}
