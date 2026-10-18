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
 * An approved substitution. Directional and rated: half a kilo of grade B standing in for a kilo
 * of grade A is the whole point of recording one, so the ratio is not optional.
 */
class ItemSubstitute extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity;

    protected $table = 'mfg_item_substitutes';

    protected $fillable = [
        'uuid', 'company_id', 'item_id', 'substitute_stock_item_id', 'ratio', 'restriction',
        'requires_approval', 'approved_by', 'approved_at', 'is_active',
    ];

    protected $casts = [
        'ratio' => 'decimal:6',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /** The material that stands in, from the finance item master. */
    public function substituteItem(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockItem::class,
            'substitute_stock_item_id');
    }

    /** Usable now: active, and either not needing approval or already carrying one. */
    public function isUsable(): bool
    {
        return (bool) $this->is_active
            && (! $this->requires_approval || $this->approved_at !== null);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['substitute_stock_item_id', 'ratio', 'requires_approval', 'approved_by', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
