<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Support\CaseState;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One due-diligence engagement, from raising a supplier to deciding about them.
 *
 * A case is not a supplier. The same company can be re-cased on renewal, on a change of ownership,
 * or when it is engaged for something materially different — and each of those needs its own file,
 * its own tier and its own decision, or the answer to "on what basis did we approve them" becomes
 * whichever assessment happened to be last.
 */
class DueDiligenceCase extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'dd_cases';

    protected $fillable = [
        'uuid', 'supplier_id', 'reference', 'state', 'tier', 'risk_score', 'risk_band',
        'raised_by', 'owner_id', 'owner_slot', 'opened_at', 'due_at', 'decided_at',
        'decided_by', 'decided_role', 'decision_reason', 'conditions', 'conditions_due',
        'conditions_met_at', 'closed_reason',
    ];

    protected $casts = [
        'tier' => 'integer',
        'risk_score' => 'decimal:2',
        'conditions' => 'array',
        'opened_at' => 'datetime',
        'due_at' => 'datetime',
        'decided_at' => 'datetime',
        'conditions_due' => 'date',
        'conditions_met_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $case) {
            $case->uuid ??= (string) Str::uuid();
            $case->opened_at ??= Carbon::now();
            $case->state ??= CaseState::DRAFT;
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(DueDiligenceCaseTransition::class, 'case_id')->orderBy('occurred_at');
    }

    public function eddTasks(): HasMany
    {
        return $this->hasMany(DueDiligenceEddTask::class, 'case_id');
    }

    /** The open case for a supplier, if there is one — a supplier has at most one at a time. */
    public static function openFor(int $supplierId): ?self
    {
        return static::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('state', array_keys(array_filter(CaseState::all(), fn ($s) => $s['open'])))
            ->orderByDesc('id')
            ->first();
    }

    public static function currentFor(int $supplierId): ?self
    {
        return static::query()->where('supplier_id', $supplierId)->orderByDesc('id')->first();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', array_keys(array_filter(CaseState::all(), fn ($s) => $s['open'])));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function stateLabel(): string
    {
        return CaseState::label($this->state);
    }

    public function isOpen(): bool
    {
        return CaseState::isOpen($this->state);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * A conditional approval whose deadline has passed with the conditions unmet.
     *
     * Asked rather than stored, because a date passing is not an event anything fires on — the case
     * simply becomes overdue the moment the clock does, whether or not a job has run.
     */
    public function conditionsLapsed(): bool
    {
        return $this->state === CaseState::CONDITIONAL_APPROVAL
            && $this->conditions_due !== null
            && $this->conditions_met_at === null
            && $this->conditions_due->isPast();
    }

    public function outstandingEdd(): int
    {
        return $this->eddTasks()->where('status', 'outstanding')->where('is_required', true)->count();
    }
}
