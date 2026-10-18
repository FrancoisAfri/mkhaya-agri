<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** TAX-REG-001: one tax obligation of one legal entity. */
class TaxRegistration extends Model
{
    use BelongsToCompany, BelongsToBusiness, HasDocuments;

    public const STATUSES = ['pending', 'active', 'suspended', 'deregistered'];

    public const FREQUENCIES = ['monthly', 'bi_monthly_even', 'bi_monthly_odd', 'four_monthly', 'six_monthly', 'annual'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_registrations';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'effective_from' => 'date', 'effective_to' => 'date', 'applicability_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TaxRegistrationVersion::class, 'registration_id')->orderByDesc('version');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(TaxPeriod::class, 'registration_id')->orderBy('start_date');
    }
}
