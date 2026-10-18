<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class FleetMaintenance extends Model
{
    use SoftDeletes, LogsActivity, HasFactory, KeepsDatabaseDefaults;

    protected static function newFactory(): \Database\Factories\Fleet\FleetMaintenanceFactory
    {
        return \Database\Factories\Fleet\FleetMaintenanceFactory::new();
    }

    protected $table = 'fleet_maintenance';

    protected $fillable = [
        'uuid', 'vehicle_id', 'maintenance_type', 'category', 'description',
        'scheduled_date', 'completed_date', 'odometer_at_service',
        'next_service_km', 'next_service_date', 'service_provider',
        'service_provider_contact', 'cost', 'labour_cost', 'parts_cost',
        'invoice_number', 'status', 'priority', 'assigned_to', 'approved_by', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'scheduled_date'     => 'date',
        'completed_date'     => 'date',
        'next_service_date'  => 'date',
        'odometer_at_service'=> 'decimal:1',
        'next_service_km'    => 'decimal:1',
        'cost'               => 'decimal:2',
        'labour_cost'        => 'decimal:2',
        'parts_cost'         => 'decimal:2',
    ];

    public function vehicle(): BelongsTo    { return $this->belongsTo(FleetVehicle::class, 'vehicle_id'); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function approver(): BelongsTo   { return $this->belongsTo(User::class, 'approved_by'); }

    public function getTotalCostAttribute(): float
    {
        return (float) (($this->labour_cost ?? 0) + ($this->parts_cost ?? 0));
    }

    public function scopeScheduled($q)   { return $q->where('status', 'scheduled'); }
    public function scopeCompleted($q)   { return $q->where('status', 'completed'); }
    public function scopeEmergency($q)   { return $q->where('priority', 'emergency'); }
    public function scopeOverdue($q)     { return $q->where('status', 'scheduled')->where('scheduled_date', '<', now()); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fleet')
            ->logOnly(['status', 'vehicle_id', 'category', 'maintenance_type', 'scheduled_date', 'completed_date', 'cost', 'labour_cost', 'parts_cost', 'invoice_number', 'priority'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
