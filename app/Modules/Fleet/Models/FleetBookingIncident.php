<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetBookingIncident extends Model
{
    protected $table = 'fleet_booking_incidents';

    protected $fillable = [
        'booking_id', 'vehicle_id', 'incident_type', 'severity', 'description',
        'incident_at', 'location', 'police_case_number', 'evidence_paths', 'created_by',
    ];

    protected $casts = [
        'incident_at'    => 'datetime',
        'evidence_paths' => 'array',
    ];

    protected $appends = ['evidence_files'];

    public function booking(): BelongsTo   { return $this->belongsTo(FleetBooking::class); }
    public function vehicle(): BelongsTo   { return $this->belongsTo(FleetVehicle::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function getEvidenceFilesAttribute(): array
    {
        return collect($this->evidence_paths ?? [])
            ->map(function ($item) {
                if (!is_array($item) || !isset($item['data'])) return null;
                return [
                    'name'     => $item['original_name'] ?? 'evidence',
                    'mime'     => $item['mime'],
                    'data_url' => 'data:' . $item['mime'] . ';base64,' . $item['data'],
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }
}
