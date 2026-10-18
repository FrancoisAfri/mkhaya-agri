<?php

namespace App\Modules\Finance\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimTravelTrip extends Model
{
    protected $table = 'claim_travel_trips';

    protected $fillable = [
        'claim_id', 'trip_date', 'departure_time', 'origin', 'destination',
        'distance_km', 'duration_minutes', 'duration_in_traffic_minutes',
        'is_peak_hour', 'traffic_condition', 'km_rate',
        'base_amount', 'peak_surcharge', 'total_amount', 'notes', 'receipt_uploaded',
    ];

    protected $casts = [
        'trip_date'                    => 'date',
        'is_peak_hour'                 => 'boolean',
        'receipt_uploaded'             => 'boolean',
        'distance_km'                  => 'decimal:3',
        'km_rate'                      => 'decimal:4',
        'base_amount'                  => 'decimal:2',
        'peak_surcharge'               => 'decimal:2',
        'total_amount'                 => 'decimal:2',
        'duration_minutes'             => 'integer',
        'duration_in_traffic_minutes'  => 'integer',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClaimAttachment::class, 'claim_travel_trip_id');
    }

    public function getTrafficColorAttribute(): string
    {
        return match ($this->traffic_condition) {
            'heavy'    => 'error',
            'moderate' => 'warning',
            default    => 'success',
        };
    }
}
