<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A work order a preventive rule raised, and what triggered it. */
class PmWorkOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_pm_work_orders';

    protected $fillable = ['company_id', 'rule_id', 'work_order_id', 'trigger'];

    public function rule(): BelongsTo { return $this->belongsTo(PmRule::class, 'rule_id'); }

    public function workOrder(): BelongsTo { return $this->belongsTo(\App\Modules\Assets\Models\AssetWorkOrder::class, 'work_order_id'); }
}
