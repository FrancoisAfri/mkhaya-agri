<?php

namespace App\Modules\HR\Payroll\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PayrollCorrection extends Model
{
    use SoftDeletes;
    protected $table = 'payroll_corrections';

    protected $fillable = [
        'uuid',
        'run_id',
        'payslip_id',
        'employee_id',
        'correction_type',
        'description',
        'suggested_action',
        'field_affected',
        'original_value',
        'corrected_value',
        'status',
        'suggested_by',
        'approved_by',
        'approved_at',
        'applied_by',
        'applied_at',
        'rejection_reason',
        'metadata',
    ];

    protected $casts = [
        'original_value' => 'decimal:2',
        'corrected_value' => 'decimal:2',
        'approved_at'    => 'datetime',
        'applied_at'     => 'datetime',
        'metadata'       => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
