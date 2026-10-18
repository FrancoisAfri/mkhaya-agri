<?php

namespace App\Modules\HR\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PayrollAuditLog extends Model
{
    protected $table = 'payroll_audit_logs';

    protected $fillable = [
        'uuid', 'run_id', 'bureau_pay_run_id', 'bureau_company_id', 'user_id',
        'action', 'subject_type', 'subject_id',
        'description', 'old_values', 'new_values', 'meta', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'meta'       => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($model) => $model->uuid ??= (string) Str::uuid());
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
