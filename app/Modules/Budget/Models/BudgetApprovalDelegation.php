<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Somebody else holds this person's approvals for a while.
 *
 * The failure it prevents is dull and constant: an approver goes on leave, the queue stops, and the
 * first anybody hears is a supplier asking why they have not been paid. Escalation as built
 * re-notifies the person who is not there.
 *
 * A delegation *adds* an approver; it never removes one. The original approver can still act if
 * they log in from the airport, which is what people actually do, and which a delegation that
 * transferred the right would prevent.
 */
class BudgetApprovalDelegation extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'budget_approval_delegations';

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $fillable = [
        'uuid', 'company_id', 'from_user_id', 'to_user_id', 'starts_on', 'ends_on',
        'subject_type', 'max_amount', 'reason', 'is_active', 'created_by',
    ];

    protected $casts = [
        'starts_on'  => 'date',
        'ends_on'    => 'date',
        'max_amount' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->uuid ??= (string) Str::uuid());
    }

    /**
     * Would a new delegation from this person clash with one they already have?
     *
     * On the model rather than in the controller so it cannot be walked around by a second entry
     * point — an importer, a console command, an API. Two live delegations covering the same day is
     * two people believing they hold the approvals, and a trail that cannot say which of them was
     * meant to.
     */
    public static function hasOverlap(int $fromUserId, string $startsOn, ?string $endsOn = null): bool
    {
        return static::query()
            ->where('from_user_id', $fromUserId)
            ->where('is_active', true)
            // An open-ended existing delegation runs to the end of time, and so does an open-ended
            // new one — which is why both sides get a sentinel rather than a null comparison.
            ->whereDate('starts_on', '<=', $endsOn ?? '9999-12-31')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn))
            ->exists();
    }

    /** In force today: switched on, started, and not yet ended. */
    public function scopeInForce(Builder $query, ?Carbon $on = null): Builder
    {
        $on ??= now();

        return $query->where('is_active', true)
            ->whereDate('starts_on', '<=', $on)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $on));
    }

    /**
     * Does this delegation let the holder act on this particular request?
     *
     * Both narrowings are optional and both are limits, not filters: a delegation for expenses does
     * not cover a new budget, and one capped at R50,000 does not cover R80,000 — in which case the
     * original approver is still the only person who can act, which is the correct answer rather
     * than a gap.
     */
    public function covers(BudgetApprovalRequest $request): bool
    {
        if ($this->subject_type !== null && $this->subject_type !== $request->subject_type) {
            return false;
        }

        if ($this->max_amount !== null && $request->amount !== null && (float) $request->amount > (float) $this->max_amount) {
            return false;
        }

        return true;
    }
}
