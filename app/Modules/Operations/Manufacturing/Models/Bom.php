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
 * A bill of materials -- one revision of one product's recipe.
 *
 * The acceptance criteria are the design: an approved revision cannot be silently overwritten, and
 * a released production order keeps the version it was costed against. So a revision is immutable
 * once approved (enforced by database triggers, not only by this class), and changing it means
 * creating the next revision and superseding this one -- which leaves both on the record.
 *
 * A production order references a bom_id, so it keeps its recipe even after engineering moves on.
 */
class Bom extends Model
{
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'mfg_boms';

    protected $fillable = [
        'uuid', 'company_id', 'item_id', 'revision', 'name', 'status', 'effective_from',
        'effective_to', 'yield_quantity', 'yield_uom_id', 'batch_size',
        'process_loss_percent', 'is_default', 'notes', 'created_by', 'approved_by',
        'approved_at', 'superseded_by_id',
    ];

    protected $casts = [
        'revision' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'yield_quantity' => 'decimal:6',
        'batch_size' => 'decimal:6',
        'process_loss_percent' => 'decimal:4',
        'is_default' => 'boolean',
        'approved_at' => 'datetime',
    ];

    /** A recipe is visible exactly when the product it builds is (see Item::booted). */
    protected static function booted(): void
    {
        static::addGlobalScope('visible_item', fn ($query) => $query->whereHas('item'));
    }

    public const DRAFT = 'DRAFT';
    public const APPROVED = 'APPROVED';
    public const SUPERSEDED = 'SUPERSEDED';
    public const OBSOLETE = 'OBSOLETE';

    public const STATUSES = [self::DRAFT, self::APPROVED, self::SUPERSEDED, self::OBSOLETE];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function yieldUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'yield_uom_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class, 'bom_id')->orderBy('line_no');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BomApproval::class, 'bom_id')->orderByDesc('acted_at');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** Only a draft may be edited. Everything else is a historical record. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isEffectiveOn(\DateTimeInterface $date): bool
    {
        if ($this->status !== self::APPROVED) {
            return false;
        }

        $day = \Illuminate\Support\Carbon::instance($date)->startOfDay();

        if ($this->effective_from && $day->lessThan($this->effective_from->startOfDay())) {
            return false;
        }

        return ! $this->effective_to || $day->lessThanOrEqualTo($this->effective_to->startOfDay());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['revision', 'status', 'yield_quantity', 'batch_size',
                'process_loss_percent', 'is_default', 'effective_from', 'effective_to',
                'approved_by', 'superseded_by_id'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
