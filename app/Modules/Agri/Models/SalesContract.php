<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A buyer agreement: what, how much, when and where, to what quality, at what price, on what terms (§9.2). */
class SalesContract extends AgriModel
{
    public const STATUSES = ['draft', 'active', 'suspended', 'completed', 'cancelled'];
    public const LIVE = ['active', 'suspended'];

    protected $table = 'agri_sales_contracts';

    protected $casts = ['quantity' => 'float', 'tolerance_pct' => 'float', 'windows' => 'array', 'accepted_grades' => 'array', 'price' => 'float',
        'formula' => 'array', 'advance_amount' => 'float', 'input_financing' => 'array', 'approved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function amendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class, 'contract_id');
    }
}
