<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Daily AR ageing snapshot per company (drives DSO/CEI trend + ageing-trend chart). */
class FinArSnapshot extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_ar_snapshots';

    protected $fillable = [
        'entity_id', 'company_id', 'as_of', 'total', 'overdue', 'current',
        'b_1_30', 'b_31_60', 'b_61_90', 'b_91_120', 'b_120_plus',
    ];

    protected $casts = [
        'as_of'      => 'date',
        'total'      => 'decimal:2',
        'overdue'    => 'decimal:2',
        'current'    => 'decimal:2',
        'b_1_30'     => 'decimal:2',
        'b_31_60'    => 'decimal:2',
        'b_61_90'    => 'decimal:2',
        'b_91_120'   => 'decimal:2',
        'b_120_plus' => 'decimal:2',
    ];
}
