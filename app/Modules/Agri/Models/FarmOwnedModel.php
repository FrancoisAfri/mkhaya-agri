<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Scopes\FarmAccessScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that belongs to one farm, and is therefore confined to the farms the signed-in person
 * may reach — a record on another farm reads as not found.
 */
abstract class FarmOwnedModel extends AgriModel
{
    protected static function booted(): void
    {
        static::addGlobalScope('farm_access', new FarmAccessScope('farm_id'));
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(FarmField::class, 'field_id');
    }
}
