<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One run of a reconciliation (§12). A later run is a new version; a signed-off one is never rewritten. */
class TaxReconciliation extends Model
{
    use BelongsToCompany, BelongsToBusiness, HasDocuments;

    public const TYPES = [
        'vat_register_to_gl' => 'VAT register against VAT control accounts',
        'payroll_to_gl' => 'Payroll taxes against their ledger accounts',
        'register_completeness' => 'VAT documents posted in Finance against the VAT register',
        'liabilities_to_payments' => 'Tax liabilities against payments recorded in Finance',
    ];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_reconciliations';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'cutoff_at' => 'datetime', 'signed_off_at' => 'datetime', 'summary' => 'array', 'diagnostics' => 'array',
        'tolerance' => 'string', 'source_total' => 'string', 'target_total' => 'string', 'difference' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(TaxPeriod::class, 'period_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaxReconciliationItem::class, 'reconciliation_id');
    }
}
