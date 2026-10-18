<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** An amount owed to (or refundable by) SARS, traceable to its return or assessment (§13). */
class TaxLiability extends Model
{
    use BelongsToCompany, BelongsToBusiness, HasDocuments;

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_liabilities';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = ['due_date' => 'date', 'original_amount' => 'string', 'adjustments' => 'string', 'paid_amount' => 'string'];

    protected static function booted(): void
    {
        static::creating(fn (self $l) => $l->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(TaxPeriod::class, 'period_id');
    }

    public function taxReturn(): BelongsTo
    {
        return $this->belongsTo(TaxReturn::class, 'return_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TaxPaymentAllocation::class, 'liability_id');
    }

    /** What is still owed: original + adjustments − paid. Decimal strings, never floats. */
    public function outstanding(): string
    {
        return bcsub(bcadd((string) $this->original_amount, (string) $this->adjustments, 2), (string) $this->paid_amount, 2);
    }
}
