<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldActivity extends FarmOwnedModel
{
    public const TYPES = ['soil_preparation', 'planting', 'fertilising', 'spraying', 'irrigation', 'weeding',
        'scouting', 'harvesting', 'pruning', 'mowing', 'feeding', 'dipping', 'other'];

    public const PLANNED = 'planned';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::PLANNED, self::IN_PROGRESS, self::COMPLETED, self::CANCELLED];

    protected $table = 'field_activities';

    protected $casts = [
        'planned_on' => 'date', 'completed_at' => 'datetime', 'area_covered' => 'decimal:4',
        'labour_hours' => 'decimal:2', 'labour_cost' => 'decimal:2', 'machine_hours' => 'decimal:2',
    ];

    public function cropPlan(): BelongsTo
    {
        return $this->belongsTo(CropPlan::class);
    }

    public function livestockGroup(): BelongsTo
    {
        return $this->belongsTo(LivestockGroup::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FarmMachine::class, 'machine_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(ActivityInput::class, 'activity_id');
    }
}
