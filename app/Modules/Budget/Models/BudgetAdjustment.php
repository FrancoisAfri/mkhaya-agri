<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BudgetAdjustment extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'budget_adjustments';

    protected $fillable = [
        'uuid', 'budget_id', 'type', 'amount', 'transfer_to_budget_id',
        'reason', 'status', 'requested_by', 'approved_by', 'acted_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'acted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (BudgetAdjustment $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('Budget Management')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }
}
