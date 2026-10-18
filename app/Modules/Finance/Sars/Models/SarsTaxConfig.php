<?php

namespace App\Modules\Finance\Sars\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's SARS registration profile — the single source of truth for every SARS
 * return header and the validation engine. One row per company.
 */
class SarsTaxConfig extends Model
{
    use BelongsToCompany;

    protected $table = 'sars_tax_configs';

    protected $fillable = [
        'company_id', 'vat_number', 'vat_registered', 'vat_frequency',
        'paye_reference', 'uif_reference', 'sdl_reference', 'sdl_liable',
        'income_tax_number', 'sic_code', 'contact_person', 'contact_email', 'efiling_username',
    ];

    protected $casts = [
        'vat_registered' => 'boolean',
        'sdl_liable'     => 'boolean',
    ];

    public const VAT_FREQUENCIES = ['monthly', 'bi_monthly', 'category_c', 'category_d'];

    /** The current tenant's config, created empty on first use. */
    public static function current(): self
    {
        $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        return $cid
            ? static::firstOrCreate(['company_id' => $cid])
            : (static::first() ?? new static());
    }
}
