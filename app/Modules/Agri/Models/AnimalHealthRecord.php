<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimalHealthRecord extends FarmOwnedModel
{
    public const TYPES = ['vaccination', 'treatment', 'deworming', 'dipping', 'vet_visit', 'diagnosis', 'castration', 'dehorning', 'other'];

    protected $table = 'animal_health_records';

    protected $casts = [
        'treated_on' => 'date', 'withdrawal_until' => 'date', 'next_due_on' => 'date',
        'quantity' => 'decimal:4', 'cost' => 'decimal:2',
    ];

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LivestockGroup::class, 'group_id');
    }
}
