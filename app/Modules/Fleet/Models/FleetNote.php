<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetNote extends Model
{
    protected $table = 'fleet_notes';

    protected $fillable = [
        'vehicle_id', 'note_type', 'notes', 'severity',
        'created_by', 'updated_by',
    ];

    public function vehicle(): BelongsTo { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function author(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeCritical($q) { return $q->where('severity', 'critical'); }
    public function scopeWarning($q)  { return $q->where('severity', 'warning'); }
}
