<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** What to count, how often and how strictly (WMS-070). Each run opens a Count. */
class CountPlan extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_count_plans';

    protected $fillable = ['uuid', 'company_id', 'name', 'warehouse_id', 'frequency', 'scope', 'scope_ids', 'abc_class', 'min_value',
        'max_lines', 'blind', 'freeze', 'recount_qty', 'recount_pct', 'recount_value', 'deadline_days', 'team', 'next_run_at',
        'last_run_at', 'is_active', 'created_by'];

    protected $casts = ['scope_ids' => 'array', 'team' => 'array', 'blind' => 'boolean', 'freeze' => 'boolean', 'is_active' => 'boolean',
        'min_value' => 'decimal:2', 'recount_qty' => 'decimal:4', 'recount_pct' => 'decimal:4', 'recount_value' => 'decimal:2',
        'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];

    public const FREQUENCIES = ['ONCE', 'DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY', 'ANNUAL', 'EVENT'];

    /** WAREHOUSE: every bin. ZONE: bins under the named zones. BINS/ITEMS/LOTS/CATEGORY: those named.
     *  ABC: items of one class. VALUE: positions above a value. RISK: where things go wrong. */
    public const SCOPES = ['WAREHOUSE', 'ZONE', 'BINS', 'ITEMS', 'CATEGORY', 'LOTS', 'ABC', 'VALUE', 'RISK'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }

    public function counts(): HasMany { return $this->hasMany(Count::class, 'plan_id'); }

    public function nextAfter(Carbon $from): ?Carbon
    {
        return match ($this->frequency) {
            'DAILY' => $from->copy()->addDay(),
            'WEEKLY' => $from->copy()->addWeek(),
            'MONTHLY' => $from->copy()->addMonthNoOverflow(),
            'QUARTERLY' => $from->copy()->addQuarterNoOverflow(),
            'ANNUAL' => $from->copy()->addYearNoOverflow(),
            default => null,
        };
    }
}
