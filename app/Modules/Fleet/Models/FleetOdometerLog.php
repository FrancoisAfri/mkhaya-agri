<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetOdometerLog extends Model
{
    protected $table = 'fleet_odometer_logs';

    protected $fillable = [
        'vehicle_id', 'reading', 'reading_date', 'recorded_by', 'source', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'reading'      => 'decimal:1',
        'reading_date' => 'date',
    ];

    public function vehicle(): BelongsTo    { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
