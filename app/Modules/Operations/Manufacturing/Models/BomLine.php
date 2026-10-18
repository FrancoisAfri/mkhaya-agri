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
 * One component of a recipe.
 *
 * `is_phantom` lives here rather than on the item because the same subassembly can be a phantom
 * grouping in one recipe and a stocked, separately built part in another -- the planner explodes
 * through it in the first case and demands it in the second.
 *
 * Lines sharing an `alternate_group` are one choice rather than several requirements.
 */
class BomLine extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_bom_lines';

    protected $fillable = [
        'uuid', 'company_id', 'bom_id', 'line_no', 'component_stock_item_id',
        'quantity_per', 'uom_id', 'scrap_percent', 'is_phantom', 'line_kind',
        'alternate_group', 'is_optional', 'notes',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'quantity_per' => 'decimal:6',
        'scrap_percent' => 'decimal:4',
        'is_phantom' => 'boolean',
        'is_optional' => 'boolean',
    ];

    public const COMPONENT = 'COMPONENT';
    public const PACKAGING = 'PACKAGING';
    public const CONSUMABLE = 'CONSUMABLE';
    public const BY_PRODUCT = 'BY_PRODUCT';

    public const KINDS = [self::COMPONENT, self::PACKAGING, self::CONSUMABLE, self::BY_PRODUCT];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class, 'bom_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }

    public function alternates(): HasMany
    {
        return $this->hasMany(BomLineAlternate::class, 'bom_line_id')->orderBy('priority');
    }

    /** The component itself, from the finance item master -- this module keeps no second copy. */
    public function component(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class,
            'component_stock_item_id');
    }

    /**
     * The component's code for a requirement listing.
     *
     * Falls back to the id rather than to an empty string: a requirement line reading "#418" is
     * traceable, and one reading "" looks like a bug in the explosion.
     */
    public function componentCode(): string
    {
        return (string) ($this->component?->code ?? "#{$this->component_stock_item_id}");
    }

    /**
     * Quantity to issue for one yield unit, scrap included.
     *
     * Scrap is added to the requirement rather than subtracted from the yield: 10% scrap on 100 kg
     * means issuing 111.11 kg so that 100 kg survives, not issuing 110 kg and falling 0.9 kg short.
     */
    public function grossQuantityPer(): float
    {
        $scrap = (float) $this->scrap_percent;

        if ($scrap <= 0) {
            return (float) $this->quantity_per;
        }

        if ($scrap >= 100) {
            throw new \DomainException(
                "Line {$this->line_no} scraps 100% or more, which has no finite requirement."
            );
        }

        return (float) $this->quantity_per / (1 - ($scrap / 100));
    }

    /** A by-product comes out of the process; it is not issued into it. */
    public function isConsumed(): bool
    {
        return $this->line_kind !== self::BY_PRODUCT;
    }
}
