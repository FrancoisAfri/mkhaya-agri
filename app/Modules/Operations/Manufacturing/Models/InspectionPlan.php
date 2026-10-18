<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What to check, when, and how much of it (MFG-009): a supplier material on receipt, a product at
 * an operation, or a finished product before it may be released.
 */
class InspectionPlan extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const INCOMING = 'INCOMING';
    public const IN_PROCESS = 'IN_PROCESS';
    public const FINAL = 'FINAL';
    // Warehouse stages (WMS-082): stock checked where it is kept, goods coming back, goods going out.
    public const STORAGE = 'STORAGE';
    public const RETURN = 'RETURN';
    public const DISPATCH = 'DISPATCH';
    public const STAGES = [self::INCOMING, self::IN_PROCESS, self::FINAL, self::STORAGE, self::RETURN, self::DISPATCH];
    /** Stages whose plan belongs to the stock item, not the manufactured item. */
    public const STOCK_ITEM_STAGES = [self::INCOMING, self::STORAGE, self::RETURN, self::DISPATCH];
    public const SAMPLING_RULES = ['FIXED', 'PERCENT', 'SQRT', 'ALL'];

    protected $table = 'mfg_inspection_plans';

    protected $fillable = [
        'uuid', 'company_id', 'code', 'name', 'stage', 'stock_item_id', 'item_id', 'supplier_id',
        'routing_operation_id', 'sample_size', 'every_qty', 'blocks_production', 'requires_certificate',
        'is_active', 'notes', 'created_by', 'sampling_rule', 'sample_pct',
    ];

    protected $casts = [
        'sample_size' => 'integer', 'every_qty' => 'decimal:6', 'blocks_production' => 'boolean',
        'requires_certificate' => 'boolean', 'is_active' => 'boolean', 'sample_pct' => 'decimal:2',
    ];

    public function characteristics(): HasMany { return $this->hasMany(InspectionCharacteristic::class, 'plan_id')->orderBy('sequence')->orderBy('id'); }

    public function item(): BelongsTo { return $this->belongsTo(Item::class, 'item_id'); }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class, 'stock_item_id')
            ->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class);
    }

    public function routingOperation(): BelongsTo { return $this->belongsTo(RoutingOperation::class, 'routing_operation_id'); }

    /**
     * How many units to inspect from a quantity: a fixed number (as plans always have), a
     * percentage, the square root plus one (the usual rule of thumb for receiving), or everything.
     * The calculated rules never ask for more units than there are.
     */
    public function sampleFor(float $qty): ?int
    {
        $whole = max(1, (int) ceil($qty));

        return match ($this->sampling_rule ?? 'FIXED') {
            'PERCENT' => $this->sample_pct !== null ? max(1, min((int) ceil($qty * (float) $this->sample_pct / 100), $whole)) : $this->sample_size,
            'SQRT' => min((int) ceil(sqrt(max($qty, 0))) + 1, $whole),
            'ALL' => $whole,
            default => $this->sample_size,
        };
    }
}
