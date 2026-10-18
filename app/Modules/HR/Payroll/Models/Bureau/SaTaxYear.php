<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;

/** SARS statutory parameters for a year of assessment. */
class SaTaxYear extends Model
{
    protected $table = 'sa_tax_years';

    protected $guarded = [];

    protected $casts = [
        'brackets'                       => 'array',
        'eti'                            => 'array',
        'lump_sum_severance'             => 'array',
        'lump_sum_withdrawal'            => 'array',
        'starts_on'                      => 'date',
        'ends_on'                        => 'date',
        'primary_rebate'                 => 'decimal:2',
        'secondary_rebate'               => 'decimal:2',
        'tertiary_rebate'                => 'decimal:2',
        'medical_credit_main'            => 'decimal:2',
        'medical_credit_first_dependant' => 'decimal:2',
        'medical_credit_additional'      => 'decimal:2',
        'uif_rate'                       => 'decimal:4',
        'uif_monthly_ceiling'            => 'decimal:2',
        'sdl_rate'                       => 'decimal:4',
        'sdl_annual_threshold'           => 'decimal:2',
        'confirmed_at'                   => 'datetime',
    ];

    public static function for(int $taxYear, string $country = 'ZA'): self
    {
        return static::where('country', $country)->where('tax_year', $taxYear)->firstOrFail();
    }

    /** The most recent CONFIRMED tax years, newest first (for the calculators & viewer). */
    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmed_at');
    }

    /** A provisional row is one the rollover cron created but an admin hasn't confirmed yet. */
    public function getIsProvisionalAttribute(): bool
    {
        return $this->confirmed_at === null;
    }

    /** Human label, e.g. "2026 (1 Mar 2025 – 28 Feb 2026)". */
    public function getLabelAttribute(): string
    {
        return (string) $this->tax_year;
    }
}
