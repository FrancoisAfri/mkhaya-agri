<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LivestockGroup extends FarmOwnedModel
{
    public const PURPOSES = ['beef', 'dairy', 'breeding', 'weaners', 'feedlot', 'wool', 'mutton', 'layers', 'broilers', 'pork', 'game', 'other'];

    public const ACTIVE = 'active';
    public const CLOSED = 'closed';

    protected $table = 'livestock_groups';

    protected $casts = ['started_on' => 'date', 'budget_amount' => 'decimal:2'];

    public function species(): BelongsTo
    {
        return $this->belongsTo(LivestockSpecies::class, 'species_id');
    }

    public function animals(): HasMany
    {
        return $this->hasMany(Animal::class, 'group_id');
    }

    /** Group-tracked stock keeps a head count; individually tracked stock counts its active animals. */
    public function isGroupTracked(): bool
    {
        return $this->species?->tracking === 'group';
    }

    public function currentHeadCount(): int
    {
        return $this->isGroupTracked()
            ? (int) $this->head_count
            : $this->animals()->where('status', Animal::ACTIVE)->count();
    }
}
