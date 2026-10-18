<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;

class FinDeliveryNoteLine extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_delivery_note_lines';

    protected $fillable = ['entity_id', 'delivery_note_id', 'order_line_id', 'description', 'quantity'];

    protected $casts = ['quantity' => 'decimal:4'];
}
