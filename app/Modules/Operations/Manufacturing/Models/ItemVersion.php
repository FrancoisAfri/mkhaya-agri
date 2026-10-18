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

/** An engineering change, with an approval and a window it applies to (MFG-002). */
class ItemVersion extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity;

    protected $table = 'mfg_item_versions';

    protected $fillable = [
        'uuid', 'company_id', 'item_id', 'version', 'status', 'effective_from',
        'effective_to', 'change_reason', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'datetime',
    ];

    public const DRAFT = 'DRAFT';
    public const APPROVED = 'APPROVED';
    public const SUPERSEDED = 'SUPERSEDED';
    public const OBSOLETE = 'OBSOLETE';

    public const STATUSES = [self::DRAFT, self::APPROVED, self::SUPERSEDED, self::OBSOLETE];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /** Approved, and inside its effective window on the given date. */
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
            ->logOnly(['version', 'status', 'effective_from', 'effective_to', 'approved_by'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
