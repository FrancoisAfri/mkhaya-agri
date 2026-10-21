<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An authorised instruction to manufacture a defined quantity of a product (MFG-006).
 *
 * Pinned to one BOM revision and one routing revision at creation; its components and operations
 * are copies of them, so an engineering change made tomorrow cannot reach an order released today.
 * Closed orders are immutable by trigger.
 */
class ProductionOrder extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasDocuments, HasPublicUuid;

    protected $table = 'mfg_production_orders';

    protected $fillable = [
        'uuid', 'company_id', 'number', 'factory_id', 'warehouse_id', 'component_warehouse_id', 'item_id',
        'bom_id', 'routing_id', 'planned_qty', 'due_date', 'priority', 'status', 'status_before_hold',
        'supervisor_id', 'demand_type', 'demand_id', 'demand_reference', 'planned_start', 'planned_finish',
        'est_material_cost', 'est_labour_cost', 'est_machine_cost', 'est_overhead_cost', 'est_subcontract_cost',
        'qty_completed', 'qty_scrapped', 'qty_reworked', 'act_material_cost', 'act_labour_cost',
        'act_machine_cost', 'act_overhead_cost', 'created_by', 'approved_by', 'approved_at', 'released_by',
        'released_at', 'started_by', 'started_at', 'completed_at', 'closed_by', 'closed_at',
        'close_journal_id', 'notes', 'standard_cost_id', 'act_scrap_cost', 'act_subcontract_cost',
        'sched_earliest_start', 'sched_finish', 'order_type',
    ];

    protected $casts = [
        'planned_qty' => 'decimal:6', 'due_date' => 'date', 'priority' => 'integer',
        'planned_start' => 'datetime', 'planned_finish' => 'datetime',
        'est_material_cost' => 'decimal:2', 'est_labour_cost' => 'decimal:2', 'est_machine_cost' => 'decimal:2',
        'est_overhead_cost' => 'decimal:2', 'est_subcontract_cost' => 'decimal:2',
        'qty_completed' => 'decimal:6', 'qty_scrapped' => 'decimal:6', 'qty_reworked' => 'decimal:6',
        'act_material_cost' => 'decimal:2', 'act_labour_cost' => 'decimal:2', 'act_machine_cost' => 'decimal:2',
        'act_overhead_cost' => 'decimal:2', 'act_scrap_cost' => 'decimal:2', 'act_subcontract_cost' => 'decimal:2',
        'sched_earliest_start' => 'date', 'sched_finish' => 'date',
        'approved_at' => 'datetime', 'released_at' => 'datetime', 'started_at' => 'datetime',
        'completed_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public const DRAFT = 'DRAFT';
    public const PLANNED = 'PLANNED';
    public const APPROVED = 'APPROVED';
    public const RELEASED = 'RELEASED';
    public const IN_PRODUCTION = 'IN_PRODUCTION';
    public const QUALITY_REVIEW = 'QUALITY_REVIEW';
    public const COMPLETED = 'COMPLETED';
    public const CLOSED = 'CLOSED';
    public const ON_HOLD = 'ON_HOLD';
    public const CANCELLED = 'CANCELLED';
    public const REJECTED = 'REJECTED';

    /** The main lifecycle, in order -- the spec's eight states. */
    public const LIFECYCLE = [self::DRAFT, self::PLANNED, self::APPROVED, self::RELEASED,
        self::IN_PRODUCTION, self::QUALITY_REVIEW, self::COMPLETED, self::CLOSED];

    public const STATUSES = [...self::LIFECYCLE, self::ON_HOLD, self::CANCELLED, self::REJECTED];

    /** Statuses in which stock may move against the order. */
    public const ACTIVE = [self::RELEASED, self::IN_PRODUCTION, self::QUALITY_REVIEW];

    public const SOURCE_TYPE = 'PRODUCTION_ORDER';

    /** An order made to a BOM, or a run recorded from what a batch actually used and made (ProductionRuns). */
    public const STANDARD = 'STANDARD';
    public const RUN = 'RUN';

    /** Confined to the factories the signed-in person is assigned to (MFG-001.09). */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id'));
    }

    public function factory(): BelongsTo { return $this->belongsTo(Factory::class, 'factory_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinWarehouse::class, 'warehouse_id'); }

    public function componentWarehouse(): BelongsTo { return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinWarehouse::class, 'component_warehouse_id'); }

    public function item(): BelongsTo { return $this->belongsTo(Item::class, 'item_id'); }

    public function bom(): BelongsTo { return $this->belongsTo(Bom::class, 'bom_id')->withoutGlobalScopes(); }

    public function routing(): BelongsTo { return $this->belongsTo(Routing::class, 'routing_id')->withoutGlobalScopes(); }

    public function supervisor(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'supervisor_id'); }

    public function components(): HasMany { return $this->hasMany(ProductionOrderComponent::class, 'order_id'); }

    public function outputs(): HasMany { return $this->hasMany(ProductionOrderOutput::class, 'order_id'); }

    public function isRun(): bool { return $this->order_type === self::RUN; }

    public function operations(): HasMany { return $this->hasMany(ProductionOrderOperation::class, 'order_id')->orderBy('sequence'); }

    public function events(): HasMany { return $this->hasMany(ProductionOrderEvent::class, 'order_id')->orderByDesc('acted_at')->orderByDesc('id'); }

    public function standardCost(): BelongsTo { return $this->belongsTo(StandardCost::class, 'standard_cost_id'); }

    public function variances(): HasMany { return $this->hasMany(OrderVariance::class, 'order_id'); }

    public function isEditable(): bool { return $this->status === self::DRAFT; }

    public function isActive(): bool { return in_array($this->status, self::ACTIVE, true); }

    public function estimatedTotal(): float
    {
        return round((float) $this->est_material_cost + (float) $this->est_labour_cost + (float) $this->est_machine_cost
            + (float) $this->est_overhead_cost + (float) $this->est_subcontract_cost, 2);
    }

    public function actualTotal(): float
    {
        return round((float) $this->act_material_cost + (float) $this->act_labour_cost + (float) $this->act_machine_cost
            + (float) $this->act_overhead_cost + (float) $this->act_subcontract_cost, 2);
    }

    /** Past its due date and not yet finished. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! in_array($this->status, [self::COMPLETED, self::CLOSED, self::CANCELLED, self::REJECTED], true)
            && $this->due_date->copy()->endOfDay()->isPast();
    }
}
