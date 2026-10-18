<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A tax reporting period of one registration (§9), separate from the accounting period. */
class TaxPeriod extends Model
{
    use BelongsToCompany, BelongsToBusiness;

    /** TAX-PER-004, in workflow order. */
    public const STATUSES = ['open', 'in_preparation', 'under_review', 'approved', 'submitted', 'accepted', 'rejected', 'closed', 'locked'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_periods';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date', 'filing_due_date' => 'date',
        'payment_due_date' => 'date', 'locked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(TaxRegistration::class, 'registration_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaxPeriodEvent::class, 'period_id')->orderByDesc('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TaxTransaction::class, 'period_id');
    }

    /** Processing is refused while locked (TAX-PER-006). */
    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    /** Values that are no longer prepared but decided: submitted, accepted, closed or locked. */
    public function isFinalised(): bool
    {
        return in_array($this->status, ['submitted', 'accepted', 'closed', 'locked'], true);
    }
}
