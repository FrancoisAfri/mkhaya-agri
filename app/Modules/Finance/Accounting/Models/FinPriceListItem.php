<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPriceListItem extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_price_list_items';

    protected $fillable = ['entity_id', 'price_list_id', 'item_id', 'description', 'grade', 'unit', 'price'];

    protected $casts = ['price' => 'decimal:4'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinItem::class, 'item_id');
    }
}
