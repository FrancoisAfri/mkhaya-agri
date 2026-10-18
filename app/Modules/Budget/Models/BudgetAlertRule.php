<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * When a company wants to be told, and when it wants to be stopped.
 *
 * The severity ladder is ordered and only the top rung refuses anything:
 *
 *   info      worth seeing on the tracking page
 *   warning   worth an email to the budget's owners
 *   critical  the same, louder — this is the one people set at 90%
 *   block     new reservations and commitments are refused past this point
 *
 * Blocking is deliberately a rule rather than a hard-coded 100%. Some companies stop at 95% to
 * leave headroom for what is already in flight; some allow overspend on operating budgets and
 * never on capital. A single coded rule would be wrong for most of them, and a rule people cannot
 * express is one they work around.
 */
class BudgetAlertRule extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'budget_alert_rules';

    protected $fillable = [
        'uuid', 'company_id', 'name', 'scope_type', 'scope_value', 'scope_id',
        'metric', 'threshold', 'severity', 'notify_owners', 'notify_roles',
        'is_active', 'description', 'created_by',
    ];

    protected $casts = [
        'threshold'     => 'decimal:2',
        'notify_owners' => 'boolean',
        'notify_roles'  => 'array',
        'is_active'     => 'boolean',
    ];

    public const SCOPE_TYPES = ['all', 'expenditure_type', 'category', 'budget'];

    /**
     * committed_pct  reserved + committed + actual, as a percentage of what is available to spend
     * actual_pct     actual spend only — the money that has genuinely left
     * available_amt  the absolute balance, for "tell me when there is under R50,000 left"
     */
    public const METRICS = ['committed_pct', 'actual_pct', 'available_amt'];

    public const SEVERITIES = ['info', 'warning', 'critical', 'block'];

    /** Ordered, so "the worst rule that applies" is a comparison rather than a lookup table. */
    public const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3, 'block' => 4];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Budget Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Does this rule watch that budget? */
    public function applies(Budget $budget): bool
    {
        return match ($this->scope_type) {
            'expenditure_type' => $budget->expenditure_type === $this->scope_value,
            'category'         => (int) $budget->budget_category_id === (int) $this->scope_id,
            'budget'           => (int) $budget->id === (int) $this->scope_id,
            default            => true,
        };
    }

    public function rank(): int
    {
        return self::SEVERITY_RANK[$this->severity] ?? 0;
    }

    /**
     * Has the measured position crossed this rule?
     *
     * @param  array{allocated:float,reserved:float,committed:float,actual:float,overrides:float,available:float}  $amounts
     */
    public function breached(array $amounts): bool
    {
        $ceiling = $amounts['allocated'] + $amounts['overrides'];
        $threshold = (float) $this->threshold;

        return match ($this->metric) {
            // A budget with no allocation has no percentage — dividing by it would report every
            // empty draft as 100% consumed and bury the real ones.
            'committed_pct' => $ceiling > 0
                && (($amounts['reserved'] + $amounts['committed'] + $amounts['actual']) / $ceiling) * 100 >= $threshold,
            'actual_pct'    => $ceiling > 0 && ($amounts['actual'] / $ceiling) * 100 >= $threshold,
            'available_amt' => $amounts['available'] <= $threshold,
            default         => false,
        };
    }

    /** What the rule measured, in the unit the rule is written in. */
    public function measure(array $amounts): float
    {
        $ceiling = $amounts['allocated'] + $amounts['overrides'];

        return match ($this->metric) {
            'committed_pct' => $ceiling > 0
                ? round((($amounts['reserved'] + $amounts['committed'] + $amounts['actual']) / $ceiling) * 100, 2)
                : 0.0,
            'actual_pct'    => $ceiling > 0 ? round(($amounts['actual'] / $ceiling) * 100, 2) : 0.0,
            default         => round($amounts['available'], 2),
        };
    }
}
