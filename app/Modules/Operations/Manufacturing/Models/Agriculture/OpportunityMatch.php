<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Why an opportunity may suit a farm, and what is not yet known. A suggestion, never an application. */
class OpportunityMatch extends ProductionModel
{
    protected $table = 'prd_opportunity_matches';

    protected $casts = ['reasons' => 'array', 'gaps' => 'array'];

    public function opportunity(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Opportunity::class, 'opportunity_id'); }

    public function farm(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\Farm::class)->withoutGlobalScopes(); }
}
