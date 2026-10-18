<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A corporate income tax computation or a provisional tax estimate (§8). Versions are kept. */
class TaxComputation extends Model
{
    use BelongsToCompany, BelongsToBusiness, HasDocuments;

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_computations';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'year_start' => 'date', 'year_end' => 'date', 'snapshot' => 'array', 'validation' => 'array',
        'prepared_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime',
        'accounting_profit' => 'string', 'add_backs' => 'string', 'deductions' => 'string', 'exempt_income' => 'string',
        'allowances' => 'string', 'losses_set_off' => 'string', 'taxable_income' => 'string', 'rate' => 'string',
        'tax' => 'string', 'credits' => 'string', 'amount_payable' => 'string', 'basic_amount' => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(TaxPeriod::class, 'period_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(TaxRegistration::class, 'registration_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TaxComputationLine::class, 'computation_id')->orderBy('sort')->orderBy('id');
    }

    public function label(): string
    {
        return $this->kind === 'cit'
            ? 'Income tax computation ' . $this->year_end->format('Y')
            : 'Provisional tax (IRP6) ' . $this->year_end->format('Y') . ' — ' . match ((int) $this->provisional_number) { 1 => 'first', 2 => 'second', default => 'third' } . ' period';
    }
}
