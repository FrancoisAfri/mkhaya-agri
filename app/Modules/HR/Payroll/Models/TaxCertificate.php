<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One tax certificate as it was issued.
 *
 * The figures are read back from here rather than recomputed, which is the whole point. An employee
 * whose February payslip is corrected in June must not silently receive a different IRP5 from the
 * one they filed their return with — and if a correction genuinely changes what they are owed, that
 * is a *reissue*, visible as such, not a quiet rewrite.
 */
class TaxCertificate extends Model
{
    use BelongsToCompany;

    protected $table = 'tax_certificates';

    /** Which engine's employee this belongs to — the same discriminator as the incentive ledger. */
    public const BUREAU = EtiClaim::BUREAU;
    public const HR = EtiClaim::HR;

    public const IRP5 = 'IRP5';
    public const IT3A = 'IT3(a)';

    public const DRAFT = 'draft';
    public const FINAL = 'final';
    public const SUBMITTED = 'submitted';
    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'tax_year'          => 'integer',
        'version'           => 'integer',
        'employee_snapshot' => 'array',
        'employer_snapshot' => 'array',
        'source_codes'      => 'array',
        'gross'             => 'decimal:2',
        'paye'              => 'decimal:2',
        'uif'               => 'decimal:2',
        'sdl'               => 'decimal:2',
        'eti'               => 'decimal:2',
        'retirement'        => 'decimal:2',
        'medical'           => 'decimal:2',
        'allowances'        => 'decimal:2',
        'fringe_benefits'   => 'decimal:2',
        'periods_worked'    => 'integer',
        'period_start'      => 'date',
        'period_end'        => 'date',
        'issued_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $certificate) => $certificate->uuid ??= (string) Str::uuid());
    }

    public function bureauCompany(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * The version in force — the newest one that has not itself been superseded.
     *
     * Expressed as "nothing supersedes me" rather than "highest version", because a cancelled
     * reissue must not leave the person with no current certificate at all.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::CANCELLED)
            ->whereNotExists(fn ($sub) => $sub
                ->selectRaw(1)
                ->from('tax_certificates as newer')
                ->whereColumn('newer.supersedes_id', 'tax_certificates.id')
                ->where('newer.status', '!=', self::CANCELLED));
    }

    public function scopeForEmployee(Builder $query, string $type, int $employeeId): Builder
    {
        return $query->where('employee_type', $type)->where('employee_id', $employeeId);
    }

    /**
     * A certificate with no PAYE on it is an IT3(a), not an IRP5.
     *
     * SARS treats them as different documents and rejects the wrong one, so it is decided from the
     * figures rather than set by whoever pressed the button.
     */
    public static function typeFor(float $paye): string
    {
        return $paye > 0 ? self::IRP5 : self::IT3A;
    }

    public function employeeName(): string
    {
        return $this->employee_snapshot['name'] ?? "Employee #{$this->employee_id}";
    }

    public function isReissue(): bool
    {
        return $this->version > 1;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
