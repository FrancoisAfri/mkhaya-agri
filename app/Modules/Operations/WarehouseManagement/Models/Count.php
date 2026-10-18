<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One stocktake or cycle count: its lines, every entry made against them, and the outcome (WMS-070..072). */
class Count extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_counts';

    protected $fillable = ['uuid', 'company_id', 'number', 'plan_id', 'warehouse_id', 'reason', 'trigger', 'status', 'blind', 'freeze',
        'recount_qty', 'recount_pct', 'recount_value', 'team', 'snapshot_at', 'deadline_at', 'completed_at', 'posted_at',
        'created_by', 'approved_by'];

    protected $casts = ['blind' => 'boolean', 'freeze' => 'boolean', 'team' => 'array', 'recount_qty' => 'decimal:4',
        'recount_pct' => 'decimal:4', 'recount_value' => 'decimal:2', 'snapshot_at' => 'datetime', 'deadline_at' => 'datetime',
        'completed_at' => 'datetime', 'posted_at' => 'datetime'];

    public const OPEN = 'OPEN';
    public const COUNTING = 'COUNTING';
    public const REVIEW = 'REVIEW';
    public const POSTED = 'POSTED';
    public const CANCELLED = 'CANCELLED';

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function plan(): BelongsTo { return $this->belongsTo(CountPlan::class, 'plan_id'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function lines(): HasMany { return $this->hasMany(CountLine::class, 'count_id'); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::POSTED, self::CANCELLED], true);
    }
}
