<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProcurementRequisitionItem extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_requisition_items';

    protected $fillable = [
        'uuid', 'procurement_requisition_id', 'name', 'description',
        'budget_category_id', 'quantity', 'unit_price', 'line_total',
        'stock_item_id',
    ];

    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementRequisitionItem $m) => $m->uuid ??= (string) Str::uuid());
        static::saving(fn (ProcurementRequisitionItem $m) => $m->line_total = round((float) $m->quantity * (float) $m->unit_price, 2));
    }
}
