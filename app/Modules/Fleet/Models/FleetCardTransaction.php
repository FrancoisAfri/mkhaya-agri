<?php

namespace App\Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FleetCardTransaction extends Model
{
    use LogsActivity;
    protected $table = 'fleet_card_transactions';

    protected $fillable = [
        'fleet_card_id', 'vehicle_id', 'driver_id', 'transaction_type',
        'transaction_at', 'merchant_name', 'merchant_city', 'amount',
        'litres', 'price_per_litre', 'odometer_reading', 'fuel_type',
        'reference', 'is_flagged', 'flag_reason', 'reconciled',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'transaction_at'   => 'datetime',
        'amount'           => 'decimal:2',
        'litres'           => 'decimal:3',
        'price_per_litre'  => 'decimal:4',
        'odometer_reading' => 'decimal:1',
        'is_flagged'       => 'boolean',
        'reconciled'       => 'boolean',
    ];

    public function card(): BelongsTo    { return $this->belongsTo(FleetCard::class, 'fleet_card_id'); }
    public function vehicle(): BelongsTo { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function driver(): BelongsTo  { return $this->belongsTo(FleetDriver::class, 'driver_id'); }

    public function scopeFlagged($q)      { return $q->where('is_flagged', true); }
    public function scopeUnreconciled($q) { return $q->where('reconciled', false); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['fleet_card_id', 'transaction_type', 'amount', 'merchant_name', 'is_flagged', 'flag_reason', 'reconciled'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
