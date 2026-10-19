<?php

namespace App\Modules\Agri\Models;


class FarmBudgetLine extends FarmOwnedModel
{
    public const CATEGORIES = ['inputs', 'labour', 'machinery', 'fuel', 'animal_health', 'feed', 'repairs', 'overheads', 'other', 'income'];

    protected $table = 'farm_budget_lines';

    protected $casts = ['amount' => 'decimal:2', 'period_start' => 'date', 'period_end' => 'date'];
}
