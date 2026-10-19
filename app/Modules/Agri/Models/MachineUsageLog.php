<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineUsageLog extends FarmOwnedModel
{
    protected $table = 'machine_usage_logs';

    protected $casts = ['used_on' => 'date', 'hours' => 'decimal:2', 'hours_reading_after' => 'decimal:1',
        'fuel_litres' => 'decimal:2', 'fuel_cost' => 'decimal:2'];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FarmMachine::class, 'machine_id');
    }
}
