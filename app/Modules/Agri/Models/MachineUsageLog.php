<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineUsageLog extends FarmOwnedModel
{
    use \App\Support\Eloquent\AliasesColumns;

    /** Fleet's table: a farm machine's record lives with its asset. */
    protected $table = 'fleet_usage_logs';

    protected function columnAliases(): array
    {
        return ['machine_id' => 'vehicle_id'];
    }

    protected $casts = ['used_on' => 'date', 'hours' => 'decimal:2', 'hours_reading_after' => 'decimal:1',
        'fuel_litres' => 'decimal:2', 'fuel_cost' => 'decimal:2', 'odometer_after' => 'float', 'distance_km' => 'float'];

    /** The farm activity the use was for: Fleet's source link, with the activity as its source. */
    public function getActivityIdAttribute(): ?int
    {
        return ($this->attributes['source_type'] ?? null) === 'agri_activity' ? (int) $this->attributes['source_id'] : null;
    }

    public function setActivityIdAttribute($value): void
    {
        $this->attributes['source_type'] = $value ? 'agri_activity' : null;
        $this->attributes['source_id'] = $value ?: null;
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FarmMachine::class, 'vehicle_id');
    }
}
