<?php

namespace App\Modules\Agri\Models;

/**
 * A traced quantity of produce: a harvest lot, or what grading, packing or processing made from
 * other lots. Its parents and children are in produce_lot_links (§8.4).
 */
class ProduceLot extends FarmOwnedModel
{
    public const KINDS = ['harvest', 'collected', 'graded', 'packed', 'processed', 'returned'];   // collected: from an outgrower (§16D)
    public const LIVE = ['available', 'quarantined'];

    protected $table = 'produce_lots';

    protected $casts = ['quantity' => 'float', 'remaining' => 'float', 'unit_cost' => 'float', 'produced_on' => 'date', 'best_before' => 'date'];

    public function isStocked(): bool
    {
        return $this->stock_item_id !== null && $this->warehouse_id !== null;
    }
}
