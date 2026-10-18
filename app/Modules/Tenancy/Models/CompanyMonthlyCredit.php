<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Extra AI tokens / SMS granted to a company for a single month (YYYY-MM). Added
 * on top of the plan allowance by AiQuota/SmsQuota. Created when a token request
 * is granted or a top-up is purchased.
 */
class CompanyMonthlyCredit extends Model
{
    protected $table = 'company_monthly_credits';

    protected $fillable = [
        'company_id', 'type', 'period', 'amount', 'source', 'token_request_id', 'note', 'created_by',
    ];

    protected $casts = ['amount' => 'integer'];

    /** Grant extra tokens/SMS to a company for the current month. */
    public static function grant(int $companyId, string $type, int $amount, string $source = 'grant', ?int $tokenRequestId = null, ?string $note = null, ?int $createdBy = null): self
    {
        return static::create([
            'company_id' => $companyId,
            'type' => $type,
            'period' => now()->format('Y-m'),
            'amount' => $amount,
            'source' => $source,
            'token_request_id' => $tokenRequestId,
            'note' => $note,
            'created_by' => $createdBy,
        ]);
    }
}
