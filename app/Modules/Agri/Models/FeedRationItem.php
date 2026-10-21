<?php

namespace App\Modules\Agri\Models;

class FeedRationItem extends AgriModel
{
    protected $table = 'feed_ration_items';

    protected $casts = ['share_pct' => 'float'];
}
