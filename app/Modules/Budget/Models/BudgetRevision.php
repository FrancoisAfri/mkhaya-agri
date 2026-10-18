<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * What the allocation was, each time it moved.
 *
 * Distinct from the adjustment rows on purpose. Those record what somebody asked for; these record
 * what the budget became — and the two diverge the moment a transfer credits a budget that
 * requested nothing, or an adjustment is approved months after it was raised.
 */
class BudgetRevision extends Model
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

    protected $table = 'budget_revisions';

    protected $fillable = [
        'uuid', 'company_id', 'budget_id', 'version', 'previous_amount', 'amount',
        'reason_type', 'source_id', 'reason', 'created_by',
    ];

    protected $casts = [
        'version'         => 'integer',
        'previous_amount' => 'decimal:2',
        'amount'          => 'decimal:2',
    ];

    public const REASON_TYPES = ['created', 'approved', 'adjustment', 'transfer_in', 'carry_forward'];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }
}
