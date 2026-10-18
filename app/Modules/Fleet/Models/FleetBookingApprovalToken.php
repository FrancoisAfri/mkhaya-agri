<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetBookingApprovalToken extends Model
{
    protected $table = 'fleet_booking_approval_tokens';

    protected $fillable = [
        'booking_id', 'approval_id', 'token', 'action', 'expires_at', 'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function booking(): BelongsTo  { return $this->belongsTo(FleetBooking::class); }
    public function approval(): BelongsTo { return $this->belongsTo(FleetBookingApproval::class); }

    public function isValid(): bool
    {
        return is_null($this->used_at) && $this->expires_at->isFuture();
    }
}
