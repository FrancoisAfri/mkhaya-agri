<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A standalone employee of a bureau client company (no platform login). Holds
 * the statutory inputs (ID/tax number, medical, retirement, directive) the SA
 * tax engine needs, banking details, and the encrypted payslip lock key.
 */
class BureauEmployee extends Model
{
    protected $table = 'bureau_employees';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'uuid', 'bureau_company_id', 'employee_number', 'first_name', 'last_name',
        'id_number', 'passport_number', 'date_of_birth', 'tax_number', 'email', 'phone',
        'start_date', 'end_date', 'status', 'occupation', 'employment_category', 'manager_employee_id', 'pay_frequency', 'payment_type',
        'basic_salary', 'pay_rate', 'bank_name', 'branch_code', 'account_number', 'account_type', 'account_holder',
        'medical_members', 'medical_dependants', 'medical_contribution', 'retirement_contribution',
        'tax_directive_percentage', 'eti_opt_in', 'eti_start_date', 'created_by',
    ];

    protected $casts = [
        'date_of_birth'            => 'date',
        'start_date'               => 'date',
        'eti_start_date'           => 'date',
        'end_date'                 => 'date',
        'basic_salary'             => 'decimal:2',
        'pay_rate'                 => 'decimal:4',
        'medical_contribution'     => 'decimal:2',
        'retirement_contribution'  => 'decimal:2',
        'tax_directive_percentage' => 'decimal:2',
        'eti_opt_in'               => 'boolean',
        'medical_members'          => 'integer',
        'medical_dependants'       => 'integer',
    ];

    protected $hidden = ['payslip_key', 'portal_token'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The self-service portal token (unguessable); generated once, idempotent. */
    public function ensurePortalToken(): string
    {
        if (blank($this->portal_token)) {
            $this->forceFill(['portal_token' => Str::random(48)])->save();
        }

        return $this->portal_token;
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(BureauPayslip::class, 'bureau_employee_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (BureauEmployee $e) => $e->uuid ??= (string) Str::uuid());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    /**
     * Who approves this person's leave.
     *
     * Self-referencing and nullable: a managing director reports to nobody, and a manager leaving
     * must not take their team's records with them — the column nulls rather than cascades.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_employee_id');
    }

    /** The people who report to this person. */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_employee_id');
    }

    public function componentAssignments(): HasMany
    {
        return $this->hasMany(BureauEmployeeComponent::class, 'bureau_employee_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Age at a given date (defaults to today) — drives tax rebates and ETI. */
    public function ageAt(?Carbon $date = null): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return $this->date_of_birth->diffInYears($date ?? now());
    }

    // ── Encrypted payslip lock key ──────────────────────────────────────────────

    public function setPayslipKeyAttribute($value): void
    {
        $this->attributes['payslip_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function payslipKey(): ?string
    {
        return ! empty($this->attributes['payslip_key']) ? Crypt::decryptString($this->attributes['payslip_key']) : null;
    }

    public function hasPayslipKey(): bool
    {
        return ! empty($this->attributes['payslip_key']);
    }
}
