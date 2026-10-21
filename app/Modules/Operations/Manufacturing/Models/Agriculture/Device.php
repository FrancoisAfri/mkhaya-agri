<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A sensor, meter, controller or gateway in a unit. */
class Device extends ProductionModel
{
    protected $table = 'prd_devices';

    protected $casts = ['allowed_commands' => 'array', 'controllable' => 'boolean', 'commands_need_approval' => 'boolean', 'last_seen_at' => 'datetime', 'last_measured_at' => 'datetime', 'calibrated_on' => 'date', 'last_value' => 'float', 'calibration_offset' => 'float', 'calibration_factor' => 'float', 'secret_issued_at' => 'datetime'];

    protected $hidden = ['secret_hash'];

    /** The house, field or line it is in (`unit` is the column holding its unit of measure). */
    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }

    /** Whether nothing has been heard from it for three times the interval it reports at. */
    public function isStale(?\Carbon\CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->last_seen_at === null || $this->last_seen_at->lt($now->copy()->subSeconds(3 * max(60, (int) $this->expected_interval_seconds)));
    }
}
