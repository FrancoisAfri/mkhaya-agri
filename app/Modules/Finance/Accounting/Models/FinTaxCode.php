<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinTaxCode extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_tax_codes';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'code', 'name', 'rate', 'type', 'jurisdiction', 'account_id', 'is_active',
    ];

    protected $casts = ['rate' => 'decimal:3', 'is_active' => 'boolean'];

    public const TYPES = ['output' => 'VAT output (sales)', 'input' => 'VAT input (purchases)', 'none' => 'No tax / exempt', 'wht' => 'Withholding'];

    protected static function booted(): void
    {
        static::creating(fn (FinTaxCode $c) => $c->uuid ??= (string) Str::uuid());
    }
}
