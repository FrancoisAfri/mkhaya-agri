<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A dated credit pull.
 *
 * Kept as history because deterioration is a diff: one row overwritten each month can say a supplier
 * is at 42 and never that they were at 68 in March, which is the event worth acting on.
 */
class SupplierCreditReport extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_credit_reports';

    protected $fillable = [
        'uuid', 'supplier_id', 'provider_code', 'score', 'score_max', 'risk_band',
        'payment_behaviour', 'defaults_count', 'judgments_count', 'judgments_value',
        'collections_count', 'insolvency', 'business_rescue', 'recommended_limit',
        'current_exposure', 'report_date', 'raw', 'verification_id', 'cost',
    ];

    protected $casts = [
        'score' => 'integer',
        'score_max' => 'integer',
        'defaults_count' => 'integer',
        'judgments_count' => 'integer',
        'collections_count' => 'integer',
        'judgments_value' => 'decimal:2',
        'recommended_limit' => 'decimal:2',
        'current_exposure' => 'decimal:2',
        'insolvency' => 'boolean',
        'business_rescue' => 'boolean',
        'report_date' => 'date',
        'raw' => 'array',
        'cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $report) => $report->uuid ??= (string) Str::uuid());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public static function latestFor(int $supplierId): ?self
    {
        return static::query()
            ->where('supplier_id', $supplierId)
            ->orderByDesc('report_date')->orderByDesc('id')
            ->first();
    }

    /**
     * The bureau's score as a 0-100 RISK figure — inverted, because bureaux score creditworthiness
     * (higher is better) and this model scores risk (higher is worse).
     */
    public function riskScore(): ?float
    {
        if ($this->score === null || ! $this->score_max) {
            return null;
        }

        return round(100 - ($this->score / $this->score_max * 100), 2);
    }
}
