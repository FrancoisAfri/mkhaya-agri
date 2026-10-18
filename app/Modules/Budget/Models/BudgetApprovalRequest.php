<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One budget decision waiting to be made.
 *
 * `subject_label` and `amount` are denormalised on purpose. The inbox lists budgets, adjustments,
 * overrides and reservations together, and a page that joined four tables to render thirty rows
 * would be slow for no benefit — the label is what the approver reads, and it is what the thing was
 * called when it was submitted.
 */
class BudgetApprovalRequest extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'budget_approval_requests';

    protected $fillable = [
        'uuid', 'company_id', 'flow_id', 'budget_id', 'subject_type', 'subject_id',
        'subject_uuid', 'subject_label', 'amount', 'currency', 'status', 'current_position',
        'requested_by', 'note', 'decided_at', 'decision_reason', 'escalated_at',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'current_position' => 'integer',
        'decided_at'       => 'datetime',
        'escalated_at'     => 'datetime',
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BudgetApprovalFlow::class, 'flow_id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(BudgetApprovalAction::class, 'request_id')->orderBy('id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** The step this request is sitting on, or null when there is no designed ladder. */
    public function currentStep(): ?BudgetApprovalStep
    {
        return $this->flow?->steps()->where('position', $this->current_position)->first();
    }

    /** How long it has been waiting — what the inbox sorts by and what escalation measures. */
    public function waitingHours(): int
    {
        return (int) $this->created_at?->diffInHours(now());
    }
}
