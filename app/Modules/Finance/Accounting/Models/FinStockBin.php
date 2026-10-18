<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A location inside a warehouse. The kind is guidance for a picker; the stock's status is what is enforced.
 *
 * WHMI (WMS-002) makes these a tree -- Zone > Aisle > Rack > Shelf > Bin -- and only a BIN holds
 * stock. A location that is blocked, structural or inactive refuses incoming stock at the ledger
 * (WMS-004), so no screen, import or API caller can put stock somewhere a person was told not to.
 */
class FinStockBin extends Model
{
    use BelongsToCompany, BelongsToEntity;

    protected $table = 'fin_stock_bins';

    protected $fillable = [
        'uuid', 'company_id', 'entity_id', 'warehouse_id', 'code', 'name', 'kind', 'is_active',
        'parent_id', 'level', 'barcode', 'status', 'status_reason', 'restricted_until', 'max_units',
        'max_weight_kg', 'max_volume_m3', 'max_pallets', 'length_cm', 'width_cm', 'height_cm',
        'requires_temperature_monitoring', 'temperature_min_c', 'temperature_max_c', 'hazmat_allowed',
        'handling_notes', 'pick_sequence', 'frozen_by_count_id',
    ];

    protected $casts = [
        'is_active' => 'boolean', 'requires_temperature_monitoring' => 'boolean', 'hazmat_allowed' => 'boolean',
        'restricted_until' => 'datetime', 'max_units' => 'decimal:4', 'max_weight_kg' => 'decimal:3',
        'max_volume_m3' => 'decimal:4', 'length_cm' => 'decimal:2', 'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2', 'temperature_min_c' => 'decimal:2', 'temperature_max_c' => 'decimal:2',
    ];

    public const KINDS = ['STORAGE', 'QUARANTINE', 'STAGING', 'PRODUCTION', 'DISPATCH', 'RECEIVING', 'RETURNS', 'IN_TRANSIT'];

    /** Outermost first. A location's parent is always exactly one level above it. */
    public const LEVELS = ['ZONE', 'AISLE', 'RACK', 'SHELF', 'BIN'];

    public const OPEN = 'OPEN';
    public const BLOCKED = 'BLOCKED';
    public const RESTRICTED = 'RESTRICTED';

    public const STATUSES = [self::OPEN, self::BLOCKED, self::RESTRICTED];

    protected static function booted(): void
    {
        static::creating(fn (self $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FinWarehouse::class, 'warehouse_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Whether a restriction is still in force. A restriction with an end time lifts itself. */
    public function isRestrictedNow(): bool
    {
        return $this->status === self::RESTRICTED
            && ($this->restricted_until === null || $this->restricted_until->isFuture());
    }

    /**
     * Why stock may not be put here, or null when it may. Read by the ledger on every incoming
     * position, so the answer is the same whichever screen or API the movement came from.
     */
    public function refusalForIncoming(): ?string
    {
        if ($this->level !== null && $this->level !== 'BIN') {
            return "Location {$this->code} is a {$this->level}, not a bin; stock is held in bins.";
        }

        // Blocking an aisle blocks every bin in it: walk up, at most four levels.
        for ($at = $this, $depth = 0; $at !== null && $depth < 5; $at = $at->parent, $depth++) {
            $refusal = $at->ownRefusal();
            if ($refusal !== null) {
                return $at->is($this) ? $refusal : "{$refusal} (it contains {$this->code})";
            }
        }

        return null;
    }

    private function ownRefusal(): ?string
    {
        $why = $this->status_reason ? ": {$this->status_reason}" : '.';

        return match (true) {
            ! $this->is_active => "Location {$this->code} is inactive.",
            $this->status === self::BLOCKED => "Location {$this->code} is blocked{$why}",
            $this->isRestrictedNow() => "Location {$this->code} is restricted{$why}",
            default => null,
        };
    }
}
