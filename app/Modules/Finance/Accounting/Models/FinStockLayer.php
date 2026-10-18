<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

class FinStockLayer extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_stock_layers';

    protected $fillable = ['entity_id', 'item_id', 'received_at', 'qty_remaining', 'unit_cost'];

    protected $casts = ['received_at' => 'date', 'qty_remaining' => 'decimal:4', 'unit_cost' => 'decimal:4'];
}
