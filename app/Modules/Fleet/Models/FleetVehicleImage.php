<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleImage extends Model
{
    protected $table = 'fleet_vehicle_images';

    protected $fillable = [
        'vehicle_id', 'file_path', 'file_name', 'file_size', 'mime_type',
        'sort_order', 'is_primary', 'uploaded_by',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    protected $appends = ['data_url'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }

    public function getDataUrlAttribute(): ?string
    {
        if (!$this->file_path) return null;
        $d = json_decode($this->file_path, true);
        if (!$d || !isset($d['data'])) return null;
        return 'data:' . $d['mime'] . ';base64,' . $d['data'];
    }
}
