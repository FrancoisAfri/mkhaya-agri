<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One month of the Employment Tax Incentive, for one employee, on either payroll engine.
 *
 * Rows exist for months that claimed nothing as well as months that did — an ineligible month is a
 * fact worth keeping, because "why did this person get nothing in June" is a question a bureau has
 * to answer long after the run that decided it. Only the eligible ones consume one of the
 * twenty-four.
 */
class EtiClaim extends Model
{
    use BelongsToCompany;

    protected $table = 'eti_claims';

    /** SARS allows the incentive for twenty-four qualifying months. */
    public const MAX_MONTHS = 24;

    /** Which engine's employee this belongs to. */
    public const BUREAU = 'bureau';
    public const HR = 'hr';

    protected $fillable = [
        'uuid', 'company_id', 'employee_type', 'employee_id', 'bureau_company_id',
        'bureau_pay_run_id', 'payroll_run_id',
        'period_key', 'period_start', 'period_end', 'tax_year', 'claim_month',
        'eligible', 'amount', 'monthly_remuneration', 'age', 'reason', 'created_by',
    ];

    protected $casts = [
        'period_start'         => 'date',
        'period_end'           => 'date',
        'eligible'             => 'boolean',
        'amount'               => 'decimal:2',
        'monthly_remuneration' => 'decimal:2',
        'claim_month'          => 'integer',
        'age'                  => 'integer',
        'tax_year'             => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    /** Only the months that actually claimed something count towards the twenty-four. */
    public function scopeClaimed(Builder $query): Builder
    {
        return $query->where('eligible', true);
    }

    /** One person's ledger, whichever engine pays them. */
    public function scopeForEmployee(Builder $query, string $type, int $id): Builder
    {
        return $query->where('employee_type', $type)->where('employee_id', $id);
    }
}
