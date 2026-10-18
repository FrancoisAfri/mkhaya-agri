<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A line of the tax register, traceable to its source document line (§6.1, §18.3). */
class TaxTransaction extends Model
{
    use BelongsToCompany, BelongsToBusiness;

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_transactions';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'document_date' => 'date', 'calculation' => 'array', 'exceptions' => 'array',
        'has_exception' => 'boolean', 'is_current' => 'boolean',
        'taxable_amount' => 'string', 'tax_amount' => 'string', 'calculated_tax' => 'string',
        'difference' => 'string', 'fx_rate' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_current', true);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(TaxPeriod::class, 'period_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(TaxRuleVersion::class, 'rule_version_id');
    }
}
