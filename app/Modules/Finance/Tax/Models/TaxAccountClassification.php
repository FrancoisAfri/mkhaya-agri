<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** How a ledger account is treated for income tax, per business (§8.1 "classify income and expenses"). */
class TaxAccountClassification extends Model
{
    use BelongsToCompany, BelongsToBusiness;

    public const TREATMENTS = [
        'taxable' => 'Taxable income',
        'exempt' => 'Exempt income',
        'deductible' => 'Deductible expense',
        'non_deductible' => 'Not deductible (added back)',
        'capital' => 'Capital — added back; allowances claimed separately',
        'ignore' => 'Not part of the computation',
    ];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_account_classifications';

    protected $guarded = ['id', 'company_id'];
}
