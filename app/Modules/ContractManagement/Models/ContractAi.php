<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAi extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_ai';

    protected $fillable = [
        'contract_id', 'status', 'provider', 'model',
        'summary', 'parties', 'key_dates', 'obligations',
        'payment_terms', 'termination', 'renewal_terms',
        'risk_score', 'compliance_score', 'complexity_score',
        'risk_factors', 'key_points', 'recommendations',
        'renewal_recommendation', 'renewal_brief', 'renewal_brief_at',
        'tokens_used', 'raw_response', 'error', 'analyzed_by', 'analyzed_at',
    ];

    protected $casts = [
        'parties' => 'array',
        'key_dates' => 'array',
        'obligations' => 'array',
        'risk_factors' => 'array',
        'key_points' => 'array',
        'recommendations' => 'array',
        'risk_score' => 'decimal:2',
        'compliance_score' => 'decimal:2',
        'complexity_score' => 'decimal:2',
        'analyzed_at' => 'datetime',
        'renewal_brief_at' => 'datetime',
    ];

    /** Keep the heavy raw payload out of JSON/Inertia responses. */
    protected $hidden = ['raw_response'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
