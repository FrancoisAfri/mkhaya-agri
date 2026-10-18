<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Shared\Traits\KeepsDatabaseDefaults;

class FleetFireExtinguisher extends Model
{
    use KeepsDatabaseDefaults;

    protected $table = 'fleet_fire_extinguishers';

    protected $fillable = [
        'vehicle_id', 'barcode', 'item', 'description',
        'weight_kg', 'serial_number', 'invoice_number',
        'purchase_order_number', 'supplier', 'date_purchased',
        'cost', 'status', 'image_path', 'document_path',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'date_purchased' => 'date',
        'image_path'     => 'array',
        'document_path'  => 'array',
        'weight_kg'      => 'decimal:2',
        'cost'           => 'decimal:2',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) return null;
        $d = is_array($this->image_path) ? $this->image_path : json_decode($this->image_path, true);
        if (!$d || !isset($d['data'])) return null;
        return 'data:' . $d['mime'] . ';base64,' . $d['data'];
    }
}
