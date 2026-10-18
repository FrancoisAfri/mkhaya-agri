<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Tolerances and production policies (MFG-001.07). A NULL factory_id is the company default that
 * every factory inherits until it sets its own.
 *
 * A NULL tolerance means "none configured", which is deliberately not the same as 0 ("must be
 * exact"). Collapsing the two is how a plant ends up with every single order flagged for variance.
 */
class FactoryPolicy extends Model
{
    use BelongsToCompany, LogsActivity;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id', true));
    }

    protected $table = 'mfg_factory_policies';

    protected $fillable = [
        'company_id', 'factory_id', 'scrap_tolerance_percent', 'yield_tolerance_percent',
        'cost_variance_tolerance_percent', 'require_qc_before_putaway', 'allow_negative_stock',
        'allow_over_issue', 'require_bom_approval', 'escalate_after_hours',
        'require_material_for_approval', 'block_on_capacity_conflict', 'require_routing', 'overhead_percent',
        'escalate_after_minutes',
    ];

    protected $casts = [
        'scrap_tolerance_percent' => 'decimal:4',
        'yield_tolerance_percent' => 'decimal:4',
        'cost_variance_tolerance_percent' => 'decimal:4',
        'require_qc_before_putaway' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'allow_over_issue' => 'boolean',
        'require_bom_approval' => 'boolean',
        'escalate_after_hours' => 'integer',
        'escalate_after_minutes' => 'integer',
        'require_material_for_approval' => 'boolean',
        'block_on_capacity_conflict' => 'boolean',
        'require_routing' => 'boolean',
        'overhead_percent' => 'decimal:4',
    ];

    /**
     * The policy that governs a factory: its own row if it has one, otherwise the company default.
     * Never null -- a company with no policy row gets the defaults the columns declare.
     */
    public static function for(?int $factoryId, ?int $companyId = null): self
    {
        $query = static::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        return ($factoryId ? (clone $query)->where('factory_id', $factoryId)->first() : null)
            ?? (clone $query)->whereNull('factory_id')->first()
            ?? new static([
                'require_qc_before_putaway' => true, 'allow_negative_stock' => false, 'allow_over_issue' => false,
                'require_bom_approval' => true, 'escalate_after_hours' => 24, 'require_material_for_approval' => true,
                'block_on_capacity_conflict' => false, 'require_routing' => true,
            ]);
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /** Is a measured variance inside the configured tolerance? No tolerance set means no opinion. */
    public function withinCostTolerance(float $variancePercent): ?bool
    {
        if ($this->cost_variance_tolerance_percent === null) {
            return null;
        }

        return abs($variancePercent) <= (float) $this->cost_variance_tolerance_percent;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
