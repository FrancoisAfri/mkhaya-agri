<?php

namespace App\Modules\Agri\Models;


class LivestockCountChange extends FarmOwnedModel
{
    public const INCREASES = ['birth', 'purchase', 'transfer_in'];

    public const DECREASES = ['death', 'sale', 'slaughter', 'theft', 'transfer_out'];

    public const REASONS = [...self::INCREASES, ...self::DECREASES, 'count_correction'];

    protected $table = 'livestock_count_changes';

    protected $casts = ['changed_on' => 'date', 'value' => 'decimal:2'];
}
