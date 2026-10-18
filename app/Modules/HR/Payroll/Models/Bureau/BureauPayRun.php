<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BureauPayRun extends Model
{
    protected $table = 'bureau_pay_runs';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_FINALISED = 'finalised';
    // The states past finalising live on PayRunLifecycle, which owns the whole sequence and the
    // moves between them. These three stay here because the rest of the codebase already names them.

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'pay_date' => 'date',
        'finalised_at' => 'datetime', 'tax_year' => 'integer', 'employee_count' => 'integer',
        'processed_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime',
        'completed_at' => 'datetime', 'reopened_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(fn (BureauPayRun $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(BureauPayslip::class, 'bureau_pay_run_id');
    }

    /** Who this run pays. */
    public function roster(): HasMany
    {
        return $this->hasMany(BureauPayRunEmployee::class, 'bureau_pay_run_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BureauPayRunApproval::class, 'bureau_pay_run_id');
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    public function rosterEmployeeIds()
    {
        return $this->roster()->pluck('bureau_employee_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(BureauPayrunInput::class, 'bureau_pay_run_id');
    }

    /**
     * Are the figures fixed?
     *
     * Everything from finalising onwards, not the literal 'finalised' string — a run that has been
     * paid is more locked than one that has only been finalised, and every caller asking this
     * question means "can this still be changed".
     */
    public function isFinalised(): bool
    {
        return in_array($this->status, [
            self::STATUS_FINALISED,
            \App\Modules\HR\Payroll\Support\PayRunLifecycle::PAID,
            \App\Modules\HR\Payroll\Support\PayRunLifecycle::COMPLETED,
        ], true);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'approved_by');
    }
}
