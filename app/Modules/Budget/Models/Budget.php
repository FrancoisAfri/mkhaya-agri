<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class Budget extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'budgets';

    protected $fillable = [
        'uuid', 'code', 'name', 'financial_year_id', 'budget_period_id', 'budget_category_id',
        'parent_id', 'expenditure_type', 'scope_type', 'scope_id', 'scope_label', 'owner_id',
        'currency', 'allocated_amount', 'status', 'description', 'created_by',
        'source_module', 'source_id', 'source_ref', 'is_managed',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'is_managed'       => 'boolean',
    ];

    /**
     * Addressed by uuid, never by the row id.
     *
     * A budget is linked to from every module that spends against one. A sequential id in that URL says how many a tenant has and invites a guess at the next
     * one; authorisation is what actually refuses it, but the id makes the guess free and makes an
     * accidental leak -- a pasted link, a screenshot, a referrer header -- carry more than it needs to.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Is this budget maintained by another module?
     *
     * A managed budget's name and allocation come from upstream and are overwritten on the next
     * sync. Everything else about it — owners, phasing, alert rules, reservations, the approval
     * trail — belongs here and is left alone.
     */
    public function isManaged(): bool
    {
        return (bool) $this->is_managed && $this->source_module !== null;
    }

    protected static function booted(): void
    {
        static::creating(fn (Budget $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Budget Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class, 'financial_year_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class, 'budget_period_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class, 'budget_id');
    }

    public function owners(): HasMany
    {
        return $this->hasMany(BudgetOwner::class, 'budget_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BudgetApproval::class, 'budget_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BudgetAdjustment::class, 'budget_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(BudgetOverride::class, 'budget_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(BudgetReservation::class, 'budget_id');
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(BudgetCommitment::class, 'budget_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(BudgetConsumption::class, 'budget_id');
    }

    /** Everything about this budget that has been routed for a decision, newest first. */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(BudgetApprovalRequest::class, 'budget_id')->latest('id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(BudgetAlert::class, 'budget_id');
    }

    /**
     * The budget this one rolls up into.
     *
     * A division head accountable for fourteen cost centres could previously see fourteen numbers
     * and never the one they answer for. The tree is what makes a consolidated position possible.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** When the allocation is planned to go, period by period. */
    public function phasings(): HasMany
    {
        return $this->hasMany(BudgetPhasing::class, 'budget_id');
    }

    /** What the allocation has been, each time it moved. */
    public function revisions(): HasMany
    {
        return $this->hasMany(BudgetRevision::class, 'budget_id');
    }

    public function scopeFilter(Builder $q, array $filters): Builder
    {
        return $q
            ->when($filters['search'] ?? null, fn ($x, $s) => $x->where(fn ($w) =>
                $w->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($x, $s) => $x->where('status', $s))
            ->when($filters['financial_year_id'] ?? null, fn ($x, $v) => $x->where('financial_year_id', $v))
            ->when($filters['expenditure_type'] ?? null, fn ($x, $v) => $x->where('expenditure_type', $v))
            ->when($filters['scope_type'] ?? null, fn ($x, $v) => $x->where('scope_type', $v));
    }
}
