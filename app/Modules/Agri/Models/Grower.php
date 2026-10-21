<?php

namespace App\Modules\Agri\Models;

/** Phase 2 §16D: outgrowers. */
class Grower extends AgriModel
{
    protected $table = 'agri_growers';

    protected $casts = ['hectares' => 'float', 'verified' => 'boolean'];

    public function party(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinParty::class, 'party_id')->withoutGlobalScopes();
    }
}
