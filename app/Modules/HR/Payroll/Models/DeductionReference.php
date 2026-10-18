<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The number a third party knows one employee's deduction by.
 *
 * A Q Link loan number, a medical-aid membership, a policy, a court order. The beneficiary's own
 * reference identifies the employer to the fund; this identifies the person, which is what a
 * deduction file has to carry for the money to be applied to anything.
 */
class DeductionReference extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'payroll_deduction_references';

    public const HR = 'hr';
    public const BUREAU = 'bureau';

    protected $fillable = [
        'uuid', 'company_id', 'employee_type', 'employee_id', 'beneficiary_id',
        'reference', 'secondary_reference', 'effective_from', 'effective_to',
        'deduction_type', 'total_amount', 'instalment', 'active', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'total_amount'   => 'decimal:2',
        'instalment'     => 'decimal:2',
        'active'         => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id');
    }

    /**
     * The references that applied on a given date.
     *
     * Dated rather than merely active, because a file for March must carry the reference that was
     * current in March. Sending this month's number for a loan settled in February applies a
     * payment to a closed account, which the fund queries and the employee chases.
     */
    public function scopeEffectiveOn(Builder $query, Carbon|string|null $date): Builder
    {
        $on = Carbon::parse($date ?? now())->toDateString();

        return $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $on))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on));
    }

    public function scopeForEmployee(Builder $query, string $type, int $employeeId): Builder
    {
        return $query->where('employee_type', $type)->where('employee_id', $employeeId);
    }
}
