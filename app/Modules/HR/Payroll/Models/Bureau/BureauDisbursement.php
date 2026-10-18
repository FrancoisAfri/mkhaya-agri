<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A payout batch submitted to a provider for a pay run. */
class BureauDisbursement extends Model
{
    protected $table = 'bureau_disbursements';

    protected $fillable = [
        'uuid', 'bureau_pay_run_id', 'bureau_company_id', 'provider',
        'reference', 'status', 'count', 'total_amount', 'response', 'created_by',
    ];

    protected $casts = ['response' => 'array', 'total_amount' => 'decimal:2', 'count' => 'integer'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(fn (BureauDisbursement $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BureauPayRun::class, 'bureau_pay_run_id');
    }
}
