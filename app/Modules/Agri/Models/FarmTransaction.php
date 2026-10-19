<?php

namespace App\Modules\Agri\Models;


class FarmTransaction extends FarmOwnedModel
{
    public const EXPENSE_CATEGORIES = ['inputs', 'labour', 'machinery', 'fuel', 'animal_health', 'feed', 'repairs', 'contractors', 'utilities', 'overheads', 'other'];

    public const SALE_CATEGORIES = ['produce', 'livestock', 'other'];

    protected $table = 'farm_transactions';

    protected $casts = ['transacted_on' => 'date', 'amount' => 'decimal:2', 'quantity' => 'decimal:3', 'reversed_at' => 'datetime'];
}
