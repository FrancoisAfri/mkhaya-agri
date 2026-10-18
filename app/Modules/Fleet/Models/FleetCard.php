<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FleetCard extends Model
{
    use SoftDeletes, LogsActivity, HasFactory;

    protected static function newFactory(): \Database\Factories\Fleet\FleetCardFactory
    {
        return \Database\Factories\Fleet\FleetCardFactory::new();
    }

    protected $table = 'fleet_cards';

    protected $fillable = [
        'uuid', 'card_number_last4', 'card_token', 'card_type', 'provider',
        'vehicle_id', 'driver_id', 'expiry_month', 'expiry_year',
        'status', 'daily_limit', 'monthly_limit', 'current_month_spend',
        'pin_set', 'notes', 'version', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'daily_limit'          => 'decimal:2',
        'monthly_limit'        => 'decimal:2',
        'current_month_spend'  => 'decimal:2',
        'pin_set'              => 'boolean',
    ];

    protected $hidden = ['card_token'];

    public function vehicle(): BelongsTo { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function driver(): BelongsTo  { return $this->belongsTo(FleetDriver::class, 'driver_id'); }

    public function transactions(): HasMany
    {
        return $this->hasMany(FleetCardTransaction::class, 'fleet_card_id');
    }

    public function getMaskedNumberAttribute(): string
    {
        return '**** **** **** ' . $this->card_number_last4;
    }

    public function scopeActive($q) { return $q->where('status', 'active'); }

    public function isOverDailyLimit(float $amount): bool
    {
        return $this->daily_limit && $amount > $this->daily_limit;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['status', 'vehicle_id', 'driver_id', 'card_type', 'provider', 'daily_limit', 'monthly_limit'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
