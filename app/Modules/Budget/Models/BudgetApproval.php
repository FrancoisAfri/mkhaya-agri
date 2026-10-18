<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BudgetApproval extends Model
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

    protected $table = 'budget_approvals';

    protected $fillable = ['uuid', 'budget_id', 'step', 'status', 'approver_id', 'comment', 'acted_at'];

    protected $casts = ['acted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (BudgetApproval $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }
}
