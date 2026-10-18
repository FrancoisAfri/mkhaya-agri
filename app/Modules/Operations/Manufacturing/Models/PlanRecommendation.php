<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the plan recommends buying or making. Proposed until a planner decides; approving it
 * creates a draft purchase request or a draft production order, never a commitment.
 */
class PlanRecommendation extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const PROPOSED = 'PROPOSED';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const CONVERTED = 'CONVERTED';

    protected $table = 'mfg_plan_recommendations';

    protected $fillable = [
        'uuid', 'company_id', 'run_id', 'stock_item_id', 'kind', 'qty', 'net_qty', 'need_by', 'release_by', 'supplier_id', 'unit_price',
        'status', 'override_qty', 'override_date', 'decision_reason', 'decided_by', 'decided_at', 'converted_type', 'converted_id',
    ];

    protected $casts = [
        'qty' => 'decimal:4', 'net_qty' => 'decimal:4', 'need_by' => 'date', 'release_by' => 'date', 'unit_price' => 'decimal:4',
        'override_qty' => 'decimal:4', 'override_date' => 'date', 'decided_at' => 'datetime',
    ];

    public function run(): BelongsTo { return $this->belongsTo(PlanRun::class, 'run_id'); }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')
            ->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class);
    }

    /** The quantity and date that count: the planner's, where they overrode the plan's. */
    public function effectiveQty(): float { return (float) ($this->override_qty ?? $this->qty); }

    public function effectiveDate(): \Illuminate\Support\Carbon { return $this->override_date ?? $this->need_by; }
}
