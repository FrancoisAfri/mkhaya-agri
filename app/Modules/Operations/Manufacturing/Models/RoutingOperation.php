<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One step of a routing.
 *
 * Precedence is by sequence: everything with a lower sequence must be finished first, except
 * operations sharing this one's parallel group, which run alongside it.
 */
class RoutingOperation extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_routing_operations';

    protected $fillable = [
        'uuid', 'company_id', 'routing_id', 'sequence', 'code', 'name', 'instructions', 'work_centre_id',
        'machine_type', 'setup_minutes', 'run_minutes', 'run_basis', 'move_minutes', 'labour_headcount',
        'labour_percent_of_time', 'expected_yield_percent', 'parallel_group', 'is_mandatory',
        'is_inspection_point', 'inspection_plan_reference', 'is_approval_gate', 'gate_permission',
        'is_subcontracted', 'subcontractor_supplier_id', 'subcontract_lead_days', 'subcontract_cost_per_unit',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'setup_minutes' => 'decimal:2',
        'run_minutes' => 'decimal:4',
        'move_minutes' => 'decimal:2',
        'labour_headcount' => 'decimal:2',
        'labour_percent_of_time' => 'decimal:4',
        'expected_yield_percent' => 'decimal:4',
        'is_mandatory' => 'boolean',
        'is_inspection_point' => 'boolean',
        'is_approval_gate' => 'boolean',
        'is_subcontracted' => 'boolean',
        'subcontract_lead_days' => 'integer',
        'subcontract_cost_per_unit' => 'decimal:4',
    ];

    public const PER_UNIT = 'PER_UNIT';
    public const PER_BATCH = 'PER_BATCH';

    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class, 'routing_id');
    }

    public function workCentre(): BelongsTo
    {
        return $this->belongsTo(WorkCentre::class, 'work_centre_id');
    }

    public function alternates(): HasMany
    {
        return $this->hasMany(RoutingOperationAlternate::class, 'operation_id')->orderBy('priority');
    }

    /** Does finishing this operation need a quality result or a signature before the next may complete? */
    public function isGate(): bool
    {
        return $this->is_inspection_point || $this->is_approval_gate;
    }
}
