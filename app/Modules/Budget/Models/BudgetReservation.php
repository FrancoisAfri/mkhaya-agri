<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BudgetReservation extends Model
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

    protected $table = 'budget_reservations';

    protected $fillable = ['uuid', 'budget_id', 'amount', 'status', 'source_type', 'source_id', 'reserved_by', 'released_at', 'reference',];

    protected $casts = ['amount' => 'decimal:2', 'released_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (BudgetReservation $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }
}
