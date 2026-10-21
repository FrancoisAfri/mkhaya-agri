<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Animal extends FarmOwnedModel
{
    // Farm records (Document Management) can be linked to it.
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    public const ACTIVE = 'active';

    /** Every way an animal leaves the herd. None of them deletes it. */
    public const EXITS = ['sold', 'died', 'culled', 'slaughtered', 'missing', 'transferred'];

    public const STATUSES = [self::ACTIVE, ...self::EXITS];

    public const SEXES = ['female', 'male', 'castrate', 'unknown'];

    public const OWNERSHIP = ['owned', 'leased', 'agisted', 'partnership', 'custody'];

    public const ACQUISITIONS = ['born', 'purchased', 'transferred'];

    protected $table = 'animals';

    protected $casts = [
        'birth_date' => 'date', 'acquired_on' => 'date', 'exited_on' => 'date', 'quarantine_until' => 'date', 'birth_date_estimated' => 'boolean',
        'acquisition_cost' => 'decimal:2', 'exit_value' => 'decimal:2', 'last_weight_kg' => 'decimal:2',
    ];

    public function species(): BelongsTo
    {
        return $this->belongsTo(LivestockSpecies::class, 'species_id');
    }

    public function breed(): BelongsTo
    {
        return $this->belongsTo(LivestockBreed::class, 'breed_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LivestockGroup::class, 'group_id');
    }

    public function dam(): BelongsTo
    {
        return $this->belongsTo(self::class, 'dam_id');
    }

    public function sire(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sire_id');
    }

    public function healthRecords(): HasMany
    {
        return $this->hasMany(AnimalHealthRecord::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AnimalEvent::class);
    }

    public function weights(): HasMany
    {
        return $this->hasMany(AnimalWeight::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
