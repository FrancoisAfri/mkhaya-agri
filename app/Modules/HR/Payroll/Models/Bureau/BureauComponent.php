<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A reusable earning/deduction definition in a company's item catalogue. */
class BureauComponent extends Model
{
    protected $table = 'bureau_components';

    public const TYPES = ['earning', 'deduction', 'company_contribution', 'fringe_benefit'];
    public const CALC_METHODS = ['fixed', 'percentage_of_basic', 'per_hour'];
    public const CATEGORIES = ['medical', 'retirement', 'risk', 'allowance', 'fringe', 'other'];

    /**
     * Standard SA benefits applied to a new company — the full benefit set with
     * correct tax treatment + SARS source codes (validate codes against the
     * current PAYE BRS before filing).
     * [code, name, type, source_code, category, taxable, affects_uif, affects_sdl, is_pretax]
     */
    public const STANDARD_BENEFITS = [
        ['TRAVEL', 'Travel allowance',            'earning',              '3701', 'allowance',  true,  true,  true,  false],
        ['CELL',   'Cellphone allowance',         'earning',              '3713', 'allowance',  true,  true,  true,  false],
        ['CAR',    'Company car fringe benefit',  'fringe_benefit',       '3802', 'fringe',     true,  false, true,  false],
        ['MED_EE', 'Medical aid (employee)',      'deduction',            '4005', 'medical',    false, false, false, false],
        ['MED_ER', 'Medical aid (employer)',      'company_contribution', '4474', 'medical',    true,  false, true,  false],
        ['PEN_EE', 'Pension fund (employee)',     'deduction',            '4001', 'retirement', false, false, false, true],
        ['PEN_ER', 'Pension fund (employer)',     'company_contribution', '4472', 'retirement', true,  false, true,  false],
        ['PROV_EE','Provident fund (employee)',   'deduction',            '4003', 'retirement', false, false, false, true],
        ['PROV_ER','Provident fund (employer)',   'company_contribution', '4473', 'retirement', true,  false, true,  false],
        ['RA',     'Retirement annuity',          'deduction',            '4006', 'retirement', false, false, false, true],
        ['GLA',    'Group life assurance',        'company_contribution', '4005', 'risk',       false, false, true,  false],
        ['UIF_X',  'Garnishee / maintenance',     'deduction',            null,   'other',      false, false, false, false],
    ];

    protected $fillable = [
        'uuid', 'bureau_company_id', 'code', 'name', 'type', 'sars_source_code', 'category', 'calc_method',
        'default_amount', 'taxable', 'taxable_portion', 'affects_uif', 'affects_sdl', 'is_pretax', 'is_recurring', 'active',
    ];

    protected $casts = [
        'default_amount' => 'decimal:4',
        'taxable'        => 'boolean',
        'taxable_portion'=> 'decimal:4',
        'affects_uif'    => 'boolean',
        'affects_sdl'    => 'boolean',
        'is_pretax'      => 'boolean',
        'is_recurring'   => 'boolean',
        'active'         => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(fn (BureauComponent $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    public function isEarning(): bool
    {
        return in_array($this->type, ['earning', 'fringe_benefit'], true);
    }
}
