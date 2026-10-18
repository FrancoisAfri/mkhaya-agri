<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One operation of the order, copied from its routing revision, with what happened on the floor. */
class ProductionOrderOperation extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_production_order_operations';

    protected $fillable = [
        'uuid', 'company_id', 'order_id', 'routing_operation_id', 'sequence', 'name', 'work_centre_id',
        'machine_type', 'parallel_group', 'is_mandatory', 'is_inspection_point', 'is_approval_gate',
        'gate_permission', 'is_subcontracted', 'planned_minutes', 'planned_labour_minutes', 'status',
        'qty_completed', 'qty_rejected', 'qty_scrapped', 'qty_reworked', 'actual_machine_minutes',
        'actual_labour_minutes', 'started_at', 'completed_at', 'completed_by', 'gate_status',
        'gate_passed_by', 'gate_passed_at', 'assigned_to', 'workstation_id', 'assigned_at',
        'sched_start', 'sched_end', 'sched_work_centre_id', 'baseline_end',
    ];

    protected $casts = [
        'sequence' => 'integer', 'is_mandatory' => 'boolean', 'is_inspection_point' => 'boolean',
        'is_approval_gate' => 'boolean', 'is_subcontracted' => 'boolean',
        'planned_minutes' => 'decimal:2', 'planned_labour_minutes' => 'decimal:2',
        'qty_completed' => 'decimal:6', 'qty_rejected' => 'decimal:6', 'qty_scrapped' => 'decimal:6',
        'qty_reworked' => 'decimal:6', 'actual_machine_minutes' => 'decimal:2', 'actual_labour_minutes' => 'decimal:2',
        'started_at' => 'datetime', 'completed_at' => 'datetime', 'gate_passed_at' => 'datetime', 'assigned_at' => 'datetime',
        'sched_start' => 'date', 'sched_end' => 'date', 'baseline_end' => 'date',
    ];

    public const PENDING = 'PENDING';
    public const READY = 'READY';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const PAUSED = 'PAUSED';
    public const COMPLETED = 'COMPLETED';
    public const SKIPPED = 'SKIPPED';

    public function order(): BelongsTo { return $this->belongsTo(ProductionOrder::class, 'order_id'); }

    public function workCentre(): BelongsTo { return $this->belongsTo(WorkCentre::class, 'work_centre_id'); }

    public function assignee(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'assigned_to'); }

    public function routingOperation(): BelongsTo { return $this->belongsTo(RoutingOperation::class, 'routing_operation_id')->withoutGlobalScopes(); }

    public function timeLogs(): HasMany { return $this->hasMany(OperationTimeLog::class, 'order_operation_id')->orderBy('id'); }

    public function isGate(): bool { return $this->is_inspection_point || $this->is_approval_gate; }

    /** Finished in a way that lets the next operation proceed: done, and any gate passed. */
    public function isCleared(): bool
    {
        if ($this->status === self::SKIPPED) {
            return true;
        }

        return $this->status === self::COMPLETED && (! $this->isGate() || $this->gate_status === 'PASSED');
    }
}
