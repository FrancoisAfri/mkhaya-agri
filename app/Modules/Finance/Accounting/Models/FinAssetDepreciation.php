<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinAssetDepreciation extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_asset_depreciation';

    protected $fillable = [
        'entity_id', 'asset_id', 'period_date', 'amount', 'book_value_after', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'period_date'      => 'date',
        'amount'           => 'decimal:2',
        'book_value_after' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FinAsset::class, 'asset_id');
    }
}
