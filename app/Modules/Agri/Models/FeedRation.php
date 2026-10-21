<?php

namespace App\Modules\Agri\Models;

/** A feed mix: its components by share of weight. */
class FeedRation extends AgriModel
{
    protected $table = 'feed_rations';

    protected $casts = ['is_active' => 'boolean'];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FeedRationItem::class, 'ration_id');
    }
}
