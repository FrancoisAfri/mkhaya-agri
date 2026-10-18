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
 * How a product is made: ordered operations at work centres (MFG-003).
 *
 * Versioned and approved like a BOM, for the same reason: a production order released against a
 * routing keeps the routing it was planned and costed with. An approved routing is immutable by
 * trigger; changing it means revising to a new draft.
 */
class Routing extends Model
{
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'mfg_routings';

    protected $fillable = [
        'uuid', 'company_id', 'item_id', 'revision', 'name', 'status', 'effective_from', 'effective_to',
        'min_batch_size', 'max_batch_size', 'standard_yield_percent', 'yield_tolerance_percent',
        'is_default', 'notes', 'created_by', 'approved_by', 'approved_at', 'superseded_by_id',
    ];

    protected $casts = [
        'revision' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'min_batch_size' => 'decimal:6',
        'max_batch_size' => 'decimal:6',
        'standard_yield_percent' => 'decimal:4',
        'yield_tolerance_percent' => 'decimal:4',
        'is_default' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public const DRAFT = 'DRAFT';
    public const APPROVED = 'APPROVED';
    public const SUPERSEDED = 'SUPERSEDED';
    public const OBSOLETE = 'OBSOLETE';

    public const STATUSES = [self::DRAFT, self::APPROVED, self::SUPERSEDED, self::OBSOLETE];

    /** Visible exactly when the product it routes is (see Item::booted). */
    protected static function booted(): void
    {
        static::addGlobalScope('visible_item', fn ($query) => $query->whereHas('item'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class, 'routing_id')->orderBy('sequence');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RoutingApproval::class, 'routing_id')->orderByDesc('acted_at');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['revision', 'status', 'min_batch_size', 'max_batch_size', 'standard_yield_percent',
                'yield_tolerance_percent', 'is_default', 'effective_from', 'effective_to', 'approved_by'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
