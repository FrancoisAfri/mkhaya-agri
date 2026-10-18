<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A unit of measure (MFG-001.05), per company rather than a shared library.
 *
 * The dimension is what makes a conversion legal: kg converts to g because both are MASS, and kg
 * to litres is refused outright, because that conversion depends on what the substance is and so
 * belongs on the item (see UomConversion).
 *
 * Every unit states its size relative to its dimension's base unit, which means any two units of
 * one dimension convert without a conversion row having to exist.
 */
class Uom extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity;

    protected $table = 'mfg_uoms';

    protected $fillable = [
        'uuid', 'company_id', 'code', 'name', 'dimension', 'is_base', 'factor_to_base',
        'decimal_places', 'is_active',
    ];

    protected $casts = [
        'is_base' => 'boolean',
        'is_active' => 'boolean',
        'factor_to_base' => 'decimal:9',
        'decimal_places' => 'integer',
    ];

    public const DIMENSIONS = ['MASS', 'VOLUME', 'LENGTH', 'AREA', 'COUNT', 'TIME', 'CONTAINER'];

    /**
     * Containers -- a pack, a pallet, a roll -- measure nothing. How many units are in a pack
     * depends entirely on the item, so a container never converts by derivation and has no base
     * unit. Putting them in COUNT with a factor of 1 made "3 packs" equal "3 units" for every item
     * that had no conversion recorded, which is a wrong answer that looks completely reasonable.
     */
    public const UNDERIVABLE = ['CONTAINER'];

    /**
     * Two units convert by derivation only inside one measurable dimension.
     *
     * A container is never derivable from another unit, not even another container: a pallet is
     * not a fixed number of packs until an item says so.
     */
    public function isCompatibleWith(self $other): bool
    {
        if (in_array($this->dimension, self::UNDERIVABLE, true)) {
            return false;
        }

        return $this->dimension === $other->dimension;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'dimension', 'is_base', 'factor_to_base', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
