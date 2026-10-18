<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One variance of one order, with its method and the figures and records it came from. */
class OrderVariance extends Model
{
    use BelongsToCompany;

    public const KINDS = ['MATERIAL_PRICE', 'MATERIAL_USAGE', 'LABOUR_RATE', 'LABOUR_EFFICIENCY', 'MACHINE', 'YIELD', 'SCRAP_REWORK', 'OVERHEAD', 'TOTAL'];

    protected $table = 'mfg_order_variances';

    protected $fillable = ['company_id', 'order_id', 'standard_cost_id', 'kind', 'amount', 'method', 'inputs', 'data_quality', 'notes', 'calculated_at'];

    protected $casts = ['amount' => 'decimal:4', 'inputs' => 'array', 'calculated_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'order_id'); }
}
