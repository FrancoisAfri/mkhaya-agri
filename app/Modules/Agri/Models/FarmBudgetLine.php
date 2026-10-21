<?php

namespace App\Modules\Agri\Models;


class FarmBudgetLine extends FarmOwnedModel
{
    public const CATEGORIES = ['inputs', 'labour', 'machinery', 'fuel', 'animal_health', 'feed', 'repairs', 'contractors', 'utilities', 'irrigation', 'energy',
        'packaging', 'transport', 'storage', 'insurance', 'rentals', 'financing', 'overheads', 'other', 'income'];

    protected $table = 'farm_budget_lines';

    protected $casts = ['amount' => 'decimal:2', 'period_start' => 'date', 'period_end' => 'date', 'approved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function revisions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FarmBudgetRevision::class, 'budget_line_id');
    }
}
