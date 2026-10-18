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

/**
 * How one kind of budget decision gets approved in this company.
 *
 * Designed rather than coded: a two-person contractor wants the owner to see everything, a
 * municipality wants cost-centre owner → finance manager → CFO above R250,000 and nothing at all
 * below it. Both are this row plus its steps.
 */
class BudgetApprovalFlow extends Model
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

    protected $table = 'budget_approval_flows';

    protected $fillable = [
        'uuid', 'company_id', 'name', 'subject_type', 'is_active', 'threshold_amount',
        'currency', 'expenditure_type', 'budget_category_id', 'escalate_after_hours',
        'description', 'created_by',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'threshold_amount'     => 'decimal:2',
        'escalate_after_hours' => 'integer',
    ];

    /**
     * What can be routed.
     *
     * Each is a decision that moves money: creating the allocation, changing it, or being allowed
     * to exceed it. Reservations are here because a company that holds funds against a request
     * before a PO exists may want that held decision reviewed too.
     */
    public const SUBJECT_TYPES = ['budget', 'adjustment', 'override', 'reservation'];

    protected static function booted(): void
    {
        static::creating(fn (self $f) => $f->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Budget Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(BudgetApprovalStep::class, 'flow_id')->orderBy('position');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(BudgetApprovalRequest::class, 'flow_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Does an amount clear the threshold and therefore need routing at all?
     *
     * A null amount always routes: "we do not know what this costs" is not a reason to skip the
     * person whose job is to ask.
     */
    public function routes(?float $amount): bool
    {
        if ($this->threshold_amount === null) {
            return true;
        }

        return $amount === null || $amount >= (float) $this->threshold_amount;
    }

    /**
     * Does this flow cover the budget in question?
     *
     * A flow with neither narrowing set covers everything. A flow that names capex covers capex
     * budgets only — and a request with no budget attached at all falls to the general flow, since
     * a narrowing we cannot evaluate is not one we may assume is satisfied.
     */
    public function coversBudget(?Budget $budget): bool
    {
        if ($this->expenditure_type !== null) {
            if (! $budget || $budget->expenditure_type !== $this->expenditure_type) {
                return false;
            }
        }

        if ($this->budget_category_id !== null) {
            if (! $budget || (int) $budget->budget_category_id !== (int) $this->budget_category_id) {
                return false;
            }
        }

        return true;
    }

    /** How narrowly written this flow is — the more specific one wins when several could apply. */
    public function specificity(): int
    {
        return ($this->budget_category_id !== null ? 2 : 0) + ($this->expenditure_type !== null ? 1 : 0);
    }
}
