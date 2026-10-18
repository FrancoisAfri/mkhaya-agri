<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FleetDriver extends Model
{
    use SoftDeletes, LogsActivity, HasFactory;

    protected static function newFactory(): \Database\Factories\Fleet\FleetDriverFactory
    {
        return \Database\Factories\Fleet\FleetDriverFactory::new();
    }

    protected $table = 'fleet_drivers';

    protected $fillable = [
        'uuid', 'user_id', 'licence_number', 'licence_type',
        'licence_expiry', 'pdp_number', 'pdp_expiry',
        'status', 'restriction_reason', 'can_drive',
        'total_trips', 'total_km', 'incidents_count',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'licence_expiry' => 'date',
        'pdp_expiry'     => 'date',
        'total_km'       => 'decimal:1',
        'can_drive'      => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(FleetBooking::class, 'driver_id');
    }

    public function vehicle(): HasMany
    {
        return $this->hasMany(FleetVehicle::class, 'assigned_driver_id');
    }

    public function scopeActive($q)    { return $q->where('status', 'active'); }
    public function scopeSuspended($q) { return $q->where('status', 'suspended'); }

    public function isEligible(): bool
    {
        return $this->can_drive && $this->status === 'active' && !$this->isLicenceExpired();
    }

    public function isLicenceExpired(): bool
    {
        return $this->licence_expiry && $this->licence_expiry->isPast();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['status', 'restriction_reason', 'can_drive', 'licence_number', 'licence_expiry', 'pdp_expiry'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
