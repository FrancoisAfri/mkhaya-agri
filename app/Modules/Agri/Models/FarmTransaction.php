<?php

namespace App\Modules\Agri\Models;


class FarmTransaction extends FarmOwnedModel
{
    public const EXPENSE_CATEGORIES = ['inputs', 'labour', 'machinery', 'fuel', 'animal_health', 'feed', 'repairs', 'contractors', 'utilities', 'irrigation', 'energy', 'packaging', 'transport', 'storage', 'insurance', 'rentals', 'financing', 'overheads', 'other'];

    public const SALE_CATEGORIES = ['produce', 'livestock', 'grant', 'subsidy', 'other'];

    protected $table = 'farm_transactions';

    protected $casts = ['transacted_on' => 'date', 'amount' => 'decimal:2', 'quantity' => 'decimal:3', 'reversed_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
