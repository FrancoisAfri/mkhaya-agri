<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One version of a tax return (§10). Each preparation is kept; an amendment supersedes, never overwrites. */
class TaxReturn extends Model
{
    use BelongsToCompany, BelongsToBusiness, HasDocuments;

    public const STATUSES = ['draft', 'prepared', 'under_review', 'approved', 'submitted', 'accepted', 'rejected', 'superseded'];

    /** `unverified`: an imported historical return with no SARS evidence — never shown as filed (§23). */
    public const SUBMISSION_STATUSES = ['not_submitted', 'pending', 'submitted', 'accepted', 'rejected', 'unknown', 'amended', 'unverified'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_returns';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'totals' => 'array', 'snapshot' => 'array', 'validation' => 'array', 'due_date' => 'date',
        'prepared_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'submitted_at' => 'datetime',
        'amount_payable' => 'string',
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

    public function registration(): BelongsTo
    {
        return $this->belongsTo(TaxRegistration::class, 'registration_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(TaxReturnForm::class, 'form_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TaxReturnLine::class, 'return_id')->orderBy('sort');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(TaxReturnSource::class, 'return_id');
    }

    public function liability(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TaxLiability::class, 'return_id')->latestOfMany();
    }

    /** Figures are fixed from approval onwards; changing them means an amended version. */
    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'submitted', 'accepted', 'rejected', 'superseded'], true);
    }
}
