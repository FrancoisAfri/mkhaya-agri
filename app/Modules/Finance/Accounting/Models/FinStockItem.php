<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinStockItem extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_stock_items';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'code', 'name', 'cost_method', 'uom', 'standard_cost',
        'qty_on_hand', 'reorder_level', 'avg_cost', 'inventory_account_id', 'cogs_account_id', 'is_active',
        'status', 'status_reason', 'status_changed_at', 'owner_party_id', 'is_valued',
    ];

    protected $casts = [
        'standard_cost' => 'decimal:4', 'qty_on_hand' => 'decimal:4', 'reorder_level' => 'decimal:4', 'avg_cost' => 'decimal:4', 'is_active' => 'boolean',
        'status_changed_at' => 'datetime', 'is_valued' => 'boolean',
    ];

    protected $attributes = ['cost_method' => 'weighted_avg', 'qty_on_hand' => 0, 'avg_cost' => 0, 'standard_cost' => 0];

    public const METHODS = ['fifo' => 'FIFO', 'weighted_avg' => 'Weighted average', 'standard' => 'Standard cost'];

    // Lifecycle (WMS-013). The ledger asks refusalFor() on every movement, so a status is a rule
    // every screen, import and API obeys, not a label on a product page.
    public const ACTIVE = 'ACTIVE';
    public const DISCONTINUED = 'DISCONTINUED';
    public const BLOCKED = 'BLOCKED';
    public const RECALLED = 'RECALLED';
    public const QUARANTINED = 'QUARANTINED';
    public const OBSOLETE = 'OBSOLETE';

    public const STATUSES = [self::ACTIVE, self::DISCONTINUED, self::BLOCKED, self::RECALLED, self::QUARANTINED, self::OBSOLETE];

    /** Movement types that bring new stock in from outside, as against putting back what left. */
    private const NEW_STOCK = ['receipt', 'mfg_receipt'];

    /** Movement types that take stock out to a customer, a production order or another place. */
    private const OUTBOUND = ['issue', 'sale', 'mfg_issue', 'transfer', 'dispatch'];

    protected static function booted(): void
    {
        static::creating(fn (FinStockItem $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function layers(): HasMany
    {
        return $this->hasMany(FinStockLayer::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FinStockMovement::class, 'item_id');
    }

    /**
     * Why this item may not move this way, or null when it may.
     *
     * A reversal is always allowed -- correcting a mistake must never depend on what has happened
     * to the item since -- and so is a count adjustment, because stock that physically exists has
     * to be recordable whatever its status. Beyond that:
     *  - discontinued: no new stock comes in; what is left may still be sold and moved;
     *  - recalled / quarantined: nothing leaves, and stock may only be moved INTO a held status;
     *  - blocked / obsolete: nothing comes in and nothing leaves.
     */
    public function refusalFor(string $type, ?string $toStatus = null): ?string
    {
        $status = $this->status ?? self::ACTIVE;

        if ($status === self::ACTIVE || in_array($type, ['reversal', 'adjustment', 'relot'], true)) {
            return null;
        }

        $label = strtolower($status);
        $new = in_array($type, self::NEW_STOCK, true);
        $out = in_array($type, self::OUTBOUND, true);

        return match ($status) {
            self::DISCONTINUED => $new ? "{$this->name} is discontinued; no new stock can be received." : null,
            self::RECALLED, self::QUARANTINED => match (true) {
                $out || $new => "{$this->name} is {$label}; it cannot be received, issued or moved.",
                $type === 'status' && $toStatus === 'AVAILABLE' => "{$this->name} is {$label}; its stock cannot be released until the item is.",
                default => null,
            },
            default => ($new || $out) ? "{$this->name} is {$label}; it cannot be received, issued or moved." : null,
        };
    }

    /**
     * Whether this company owns the stock and so carries it at a value. False for goods a 3PL
     * holds for a client (owner_party_id): they are counted, located and moved like any other, but
     * they are not this company's asset and post nothing to its books.
     */
    public function isValued(): bool
    {
        return $this->is_valued ?? true;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Current stock value under the item's costing method. */
    public function stockValue(): float
    {
        return match ($this->cost_method) {
            'fifo'     => round((float) $this->layers()->sum(\DB::raw('qty_remaining * unit_cost')), 2),
            'standard' => round((float) $this->qty_on_hand * (float) $this->standard_cost, 2),
            default    => round((float) $this->qty_on_hand * (float) $this->avg_cost, 2),
        };
    }
}
