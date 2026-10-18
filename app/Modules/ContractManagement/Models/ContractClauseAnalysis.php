<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractClauseAnalysis extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_clause_analyses';

    protected $fillable = [
        'contract_id',
        'status',
        'missing_required',
        'deviations',
        'summary',
        'provider',
        'model',
        'error',
        'analyzed_by',
        'analyzed_at',
    ];

    protected $casts = [
        'missing_required' => 'array',
        'deviations' => 'array',
        'analyzed_at' => 'datetime',
    ];

    protected $hidden = [];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
