<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The engineering definition of a thing (MFG-002) -- an extension of the finance stock item, not a
 * replacement for it.
 *
 * fin_stock_items already holds the code, the costing method, the quantity on hand and the
 * inventory accounts. Keeping a second item list would mean two records disagreeing the first time
 * somebody renamed one, so this row carries only what engineering needs and finance has no view
 * on: the grade, the drawing, the shelf life, and what kind of thing it is in production.
 */
class Item extends Model
{
    use BelongsToCompany, HasDocuments, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'mfg_items';

    protected $fillable = [
        'uuid', 'company_id', 'stock_item_id', 'kind', 'category_id', 'specification',
        'default_uom_id', 'material_grade', 'colour', 'size', 'weight_kg', 'dimensions',
        'attributes', 'shelf_life_days', 'storage_conditions', 'handling_instructions',
        'is_lot_tracked', 'is_serial_tracked', 'is_active', 'created_by', 'production_strategy', 'factory_scope',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'attributes' => 'array',
        'weight_kg' => 'decimal:6',
        'shelf_life_days' => 'integer',
        'is_lot_tracked' => 'boolean',
        'is_serial_tracked' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * An engineering row is visible exactly when its stock item is.
     *
     * Stock items belong to one trading entity and carry the entity scope; this row holds no
     * entity of its own because it would only ever repeat its stock item's. Without this, a user
     * in one business listed products and saw every other business's engineering rows with a
     * blank code and name -- the stock item they point at was filtered out from under them.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('visible_stock_item', fn ($query) => $query->whereHas('stockItem'));
    }

    public const KINDS = [
        'FINISHED', 'INTERMEDIATE', 'SUBASSEMBLY', 'RAW_MATERIAL',
        'CONSUMABLE', 'PACKAGING', 'BY_PRODUCT',
        // Bought and sold without being made or consumed here -- a distributor's stock. Added by
        // WHMI, which keeps every item's physical profile on this row rather than a copy.
        'TRADED',
    ];

    /** The kinds that can be manufactured, and so can carry a bill of materials. */
    public const MANUFACTURED_KINDS = ['FINISHED', 'INTERMEDIATE', 'SUBASSEMBLY'];

    /**
     * The item master row. This module holds the engineering view; the code, the name, the costing
     * method and the quantity on hand all live on the finance record and are never copied here.
     */
    /** "CODE — name", for a document link to read as a product rather than as an id. */
    public function getDisplayNameAttribute(): string
    {
        $stock = $this->stockItem;

        return $stock ? trim("{$stock->code} — {$stock->name}") : "Product #{$this->id}";
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class,
            'stock_item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function defaultUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'default_uom_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ItemVersion::class, 'item_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ItemDocument::class, 'item_id');
    }

    public function substitutes(): HasMany
    {
        return $this->hasMany(ItemSubstitute::class, 'item_id');
    }

    public function qualityRequirements(): HasMany
    {
        return $this->hasMany(ItemQualityRequirement::class, 'item_id');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class, 'item_id');
    }

    public function isManufactured(): bool
    {
        return in_array($this->kind, self::MANUFACTURED_KINDS, true);
    }

    /**
     * The revision a production order raised on this date would be costed against.
     *
     * Defaults to today. Not "the default flag" -- that answers what is made from now on, and an
     * order dated last quarter, or scheduled for next month, needs the recipe that applies then.
     */
    public function applicableBom(?\DateTimeInterface $asOf = null): ?Bom
    {
        return app(\App\Modules\Operations\Manufacturing\Services\BomResolver::class)
            ->applicable($this->id, $asOf);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'category_id', 'material_grade', 'colour', 'size', 'weight_kg',
                'shelf_life_days', 'is_lot_tracked', 'is_serial_tracked', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
