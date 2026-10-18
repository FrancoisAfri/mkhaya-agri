<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Finance\Tax\Concerns\BelongsToBusiness;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A filing, payment or manual deadline on the compliance calendar (§14). */
class TaxComplianceDeadline extends Model
{
    use BelongsToCompany, BelongsToBusiness;

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_compliance_deadlines';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'due_date' => 'date', 'reminder_days' => 'array', 'reminders_sent' => 'array',
        'escalated_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->uuid ??= (string) Str::uuid());
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
}
