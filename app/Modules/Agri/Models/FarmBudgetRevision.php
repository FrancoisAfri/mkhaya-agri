<?php

namespace App\Modules\Agri\Models;

/** A change to an approved budget line, waiting for or carrying its approval; the line's history. */
class FarmBudgetRevision extends FarmOwnedModel
{
    protected $table = 'farm_budget_revisions';

    protected $casts = ['from_amount' => 'float', 'to_amount' => 'float', 'decided_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
