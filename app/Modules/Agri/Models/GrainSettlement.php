<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A buyer's settlement statement (delivery receipts and what they pay for them), matched to our load tickets. */
class GrainSettlement extends AgriModel
{
    protected $table = 'agri_grain_settlements';

    protected $hidden = ['file_data'];

    protected $casts = ['statement_date' => 'date', 'period_from' => 'date', 'period_to' => 'date', 'charges' => 'float', 'totals' => 'array',
        'journal_ids' => 'array', 'matched_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'posted_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function lines(): HasMany
    {
        return $this->hasMany(GrainSettlementLine::class, 'settlement_id')->orderBy('line_no');
    }
}
