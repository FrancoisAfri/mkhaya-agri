<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimRiskScore extends Model
{
    protected $table = 'claim_risk_scores';

    protected $fillable = [
        'claim_id', 'risk_score', 'risk_level', 'flags', 'explanation',
        'is_duplicate', 'is_inflated', 'is_splitting', 'missing_receipt',
        'out_of_policy', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'flags'          => 'array',
        'is_duplicate'   => 'boolean',
        'is_inflated'    => 'boolean',
        'is_splitting'   => 'boolean',
        'missing_receipt'=> 'boolean',
        'out_of_policy'  => 'boolean',
        'risk_score'     => 'decimal:2',
        'reviewed_at'    => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getRiskColorAttribute(): string
    {
        return match ($this->risk_level) {
            'critical' => 'error',
            'high'     => 'deep-orange',
            'medium'   => 'warning',
            default    => 'success',
        };
    }
}
