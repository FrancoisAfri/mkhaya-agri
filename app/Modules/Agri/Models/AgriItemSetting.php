<?php

namespace App\Modules\Agri\Models;

/** What a stock item is to a farm: its category, preferred supplier, default store and tracking. */
class AgriItemSetting extends AgriModel
{
    protected $table = 'agri_item_settings';

    public const CATEGORIES = ['seed', 'fertiliser', 'crop_protection', 'feed', 'veterinary', 'fuel', 'lubricant', 'spare_part', 'packaging',
        'produce', 'tool', 'ppe', 'consumable', 'other'];

    protected $casts = ['lot_tracked' => 'boolean', 'serial_tracked' => 'boolean'];
}
