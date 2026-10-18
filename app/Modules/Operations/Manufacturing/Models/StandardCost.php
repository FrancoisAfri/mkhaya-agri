<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An approved standard cost for a product, priced line by line (MFG-011). Immutable once approved. */
class StandardCost extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const DRAFT = 'DRAFT';
    public const APPROVED = 'APPROVED';
    public const SUPERSEDED = 'SUPERSEDED';

    protected $table = 'mfg_standard_costs';

    protected $fillable = [
        'uuid', 'company_id', 'item_id', 'version', 'status', 'costing_qty', 'bom_id', 'routing_id', 'expected_output',
        'material_cost', 'labour_cost', 'machine_cost', 'overhead_cost', 'subcontract_cost', 'total_cost', 'unit_cost',
        'labour_minutes', 'machine_minutes', 'overhead_percent', 'warnings', 'effective_from', 'notes', 'created_by',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'version' => 'integer', 'costing_qty' => 'decimal:6', 'expected_output' => 'decimal:6',
        'material_cost' => 'decimal:4', 'labour_cost' => 'decimal:4', 'machine_cost' => 'decimal:4',
        'overhead_cost' => 'decimal:4', 'subcontract_cost' => 'decimal:4', 'total_cost' => 'decimal:4',
        'unit_cost' => 'decimal:6', 'labour_minutes' => 'decimal:4', 'machine_minutes' => 'decimal:4',
        'overhead_percent' => 'decimal:4', 'warnings' => 'array', 'effective_from' => 'date', 'approved_at' => 'datetime',
    ];

    public function item(): BelongsTo { return $this->belongsTo(Item::class, 'item_id'); }

    public function lines(): HasMany { return $this->hasMany(StandardCostLine::class, 'standard_cost_id')->orderBy('id'); }

    public function bom(): BelongsTo { return $this->belongsTo(Bom::class, 'bom_id')->withoutGlobalScopes(); }

    public function routing(): BelongsTo { return $this->belongsTo(Routing::class, 'routing_id')->withoutGlobalScopes(); }

    /** Per good unit, by element -- what variances are measured against. */
    public function perUnit(string $element): float
    {
        $out = (float) $this->expected_output;

        return $out > 0 ? (float) $this->{$element.'_cost'} / $out : 0.0;
    }
}
