<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * M3 — Asset Maintenance Record
 * 
 * Individual maintenance event (scheduled or ad-hoc).
 * Links to schedule if it's a scheduled maintenance execution.
 */
class AssetMaintenance extends Model
{
    use SoftDeletes;
    protected $table = 'asset_maintenances';

    protected $fillable = [
        'uuid',
        'asset_id',
        'maintenance_schedule_id',  // NULL if ad-hoc
        'maintenance_type',         // preventive | corrective | predictive | breakdown
        'maintenance_date',
        'completed_date',
        'performed_by_user_id',
        'vendor_name',
        'vendor_contact',
        'description',
        'work_performed',
        'parts_replaced',
        'cost',
        'downtime_hours',
        'status',                   // scheduled | in_progress | completed | cancelled
        'next_due_date',
        'notes',
    ];

    protected $casts = [
        'maintenance_date' => 'date',
        'completed_date'   => 'date',
        'next_due_date'    => 'date',
        'cost'             => 'decimal:2',
        'downtime_hours'   => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AssetMaintenanceSchedule::class, 'maintenance_schedule_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    // Helpers
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'scheduled' && $this->maintenance_date < now();
    }
}
