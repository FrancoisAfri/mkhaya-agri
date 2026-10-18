<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One decision, kept.
 *
 * Nothing updates these rows. `actor_name` is stored alongside `user_id` so the trail still reads
 * after an account is deactivated — an audit record that resolves to a blank name is one that
 * cannot be defended.
 */
class BudgetApprovalAction extends Model
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

    protected $table = 'budget_approval_actions';

    protected $fillable = [
        'uuid', 'company_id', 'request_id', 'step_id', 'position', 'user_id',
        'actor_name', 'decision', 'comment', 'acted_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'acted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BudgetApprovalRequest::class, 'request_id');
    }
}
