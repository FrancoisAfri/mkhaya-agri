<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A threshold that has actually been crossed.
 *
 * One row per (budget, rule) while the condition holds, re-stamped rather than re-raised on each
 * sweep. An alerts page that grows a new row every night is one people stop opening, and an alert
 * nobody opens is the same as no alert.
 *
 * Resolution is automatic: when the condition stops being true — an increase is approved, a
 * commitment is released — the sweep closes the alert. Acknowledgement is separate and means
 * something different: somebody has seen it and is dealing with it, and it should stop shouting
 * while they do.
 */
class BudgetAlert extends Model
{
    use BelongsToCompany;

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'budget_alerts';

    protected $fillable = [
        'uuid', 'company_id', 'budget_id', 'rule_id', 'severity', 'metric', 'threshold',
        'observed', 'message', 'status', 'acknowledged_by', 'acknowledged_at',
        'acknowledgement', 'resolved_at', 'notified_at', 'last_seen_at',
    ];

    protected $casts = [
        'threshold'       => 'decimal:2',
        'observed'        => 'decimal:2',
        'acknowledged_at' => 'datetime',
        'resolved_at'     => 'datetime',
        'notified_at'     => 'datetime',
        'last_seen_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(BudgetAlertRule::class, 'rule_id');
    }

    /** Everything still true — acknowledged included, because acknowledging is not fixing. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'resolved');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
