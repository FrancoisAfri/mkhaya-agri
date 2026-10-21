<?php

namespace App\Modules\Agri\Models;

class SupplierDispute extends AgriModel
{
    protected $table = 'supplier_disputes';

    public const KINDS = ['short_delivery', 'damaged', 'wrong_item', 'quality', 'price', 'late', 'invoice', 'other'];

    protected $casts = ['amount' => 'float', 'resolved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
