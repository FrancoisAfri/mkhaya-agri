<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A grant, loan, programme, tender or market a farm might take up. */
class Opportunity extends ProductionModel
{
    protected $table = 'prd_opportunities';

    protected $casts = ['eligibility' => 'array', 'opens_on' => 'date', 'closes_on' => 'date', 'amount_max' => 'float'];

    public function matches(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(OpportunityMatch::class, 'opportunity_id'); }
}
