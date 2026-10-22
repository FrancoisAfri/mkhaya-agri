<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A futures or options position held with a broker, recorded here (no trading integration). */
class Hedge extends AgriModel
{
    public const OPEN = 'open';
    public const STATUSES = ['open', 'closed', 'expired', 'exercised'];

    protected $table = 'agri_hedges';

    protected $casts = ['strike' => 'float', 'premium' => 'float', 'contracts' => 'float', 'contract_size' => 'float', 'quantity' => 'float',
        'entry_price' => 'float', 'entry_date' => 'date', 'exit_price' => 'float', 'exit_date' => 'date', 'realised_pnl' => 'float',
        'last_mark_price' => 'float', 'last_mark_date' => 'date', 'unrealised_pnl' => 'float'];

    public function marks(): HasMany
    {
        return $this->hasMany(HedgeMark::class, 'hedge_id')->orderBy('mark_date');
    }
}
