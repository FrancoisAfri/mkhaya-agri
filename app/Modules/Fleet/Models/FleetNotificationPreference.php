<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetNotificationPreference extends Model
{
    protected $table = 'fleet_notification_preferences';

    protected $fillable = ['user_id', 'channel', 'event_type', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public const CHANNELS = ['email', 'sms', 'whatsapp', 'push', 'in_app'];

    public const EVENTS = [
        'booking_approved'    => 'Booking Approved',
        'booking_rejected'    => 'Booking Rejected',
        'booking_collected'   => 'Booking Collected',
        'high_risk_vehicle'   => 'High Risk Vehicle Alert',
        'anomaly_detected'    => 'Fuel Anomaly Detected',
        'document_expiry'     => 'Document Expiring',
        'maintenance_due'     => 'Maintenance Due',
        'incident_reported'   => 'Incident Reported',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
