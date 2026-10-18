<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FleetVehicleGeneralCost extends Model
{
    use SoftDeletes;

    protected $table = 'fleet_vehicle_general_costs';

    protected $fillable = [
        'uuid', 'vehicle_id', 'booking_id', 'date', 'document_number', 'supplier_name',
        'cost_type', 'cost', 'description', 'receipt_path', 'person_responsible_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'cost' => 'decimal:2',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }
}
