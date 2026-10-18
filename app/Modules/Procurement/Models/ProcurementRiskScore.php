<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class ProcurementRiskScore extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_risk_scores';

    protected $fillable = [
        'scope_type', 'scope_id', 'label', 'score', 'band', 'factors', 'computed_at',
    ];

    protected $casts = [
        'score'       => 'decimal:2',
        'computed_at' => 'datetime',
    ];

    public function factorList(): array
    {
        return json_decode((string) $this->factors, true) ?: [];
    }
}
