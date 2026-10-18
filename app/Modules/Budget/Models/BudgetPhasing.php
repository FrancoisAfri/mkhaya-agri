<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * How much of a budget is expected to go in each period.
 *
 * The reason this exists: without it, "budget vs actual" in month four compares four months of
 * spend against twelve months of allocation, and every budget looks comfortable right up to the
 * month it runs out. A phased plan is what turns utilisation into a question about pace.
 */
class BudgetPhasing extends Model
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

    protected $table = 'budget_phasings';

    protected $fillable = [
        'uuid', 'company_id', 'budget_id', 'budget_period_id', 'amount', 'note',
        'forecast_amount', 'forecast_note', 'forecast_by', 'forecast_at',
        'variance_explanation', 'explained_by', 'explained_at',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'forecast_amount' => 'decimal:2',
        'forecast_at'     => 'datetime',
        'explained_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class, 'budget_period_id');
    }
}
